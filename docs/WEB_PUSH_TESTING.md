# Manual de pruebas: notificaciones Web Push

## Alcance y estado

El backend guarda las suscripciones por usuario y envía notificaciones a través
del servicio push del navegador. La entrega a la barra de notificaciones la
termina el navegador mediante un service worker; el backend por sí solo no
puede mostrar una notificación en pantalla.

El backend está preparado con estos componentes:

- `GET /api/push/public-key`: devuelve la VAPID pública a usuarios autenticados
  activos con rol `admin` o `mechanic`.
- `POST /api/push/subscribe`: registra o actualiza una suscripción por el hash
  SHA-256 del endpoint.
- `POST /api/push/unsubscribe`: elimina una suscripción por endpoint y es
  idempotente.
- `POST /api/push/test`: solo admin; envía un mensaje de prueba a las
  suscripciones de todos los admins y mecánicos activos sin crear alertas
  persistentes.
- Las alertas de órdenes notifican a admins. El PATCH de asignación notifica
  solo a los mecánicos nuevos, y no envía asignaciones cuando la orden queda
  `delivered` o `cancelled`.
- Los fallos del servicio push se registran en el log del servidor; no deben
  revertir la orden o alerta ya guardada.

Los pasos siguientes requieren completar la configuración del servidor y una
integración de navegador para comprobar la entrega visual real.

## 1. Preparar las claves y el servidor

1. Generar un par nuevo de claves VAPID en una máquina de confianza:

   ```powershell
   npx web-push generate-vapid-keys
   ```

2. Crear `.env` local desde el ejemplo y completar los valores generados:

   ```powershell
   Copy-Item .env.example .env
   ```

   ```dotenv
   VAPID_PUBLIC_KEY=...
   VAPID_PRIVATE_KEY=...
   VAPID_SUBJECT=mailto:contacto@taller.com
   ```

   `.env` está excluido de Git y de la imagen Docker. No copiar la clave privada
   al frontend, Swagger, logs, tickets ni al repositorio. Guardar la clave
   privada de forma segura en el entorno de despliegue.

3. En el servidor, aplicar una sola vez
   `database/migrations/20261106_create_push_subscriptions.sql` a la base
   `taller`. Se puede ejecutar con un cliente MariaDB autorizado o DBeaver.

4. Construir y recrear el backend para que reciba las variables:

   ```powershell
   docker compose build php_backend
   docker compose up -d php_backend
   ```

5. Comprobar que el contenedor ve las variables sin imprimir los secretos:

   ```powershell
   docker compose exec php_backend php -r "foreach (['VAPID_PUBLIC_KEY','VAPID_PRIVATE_KEY','VAPID_SUBJECT'] as `$key) { if (getenv(`$key) === false || getenv(`$key) === '') { fwrite(STDERR, `$key . ' no configurada' . PHP_EOL); exit(1); } } echo 'VAPID configurado', PHP_EOL;"
   ```

## 2. Obtener un JWT y probar autenticación

En PowerShell, iniciar sesión con un usuario activo admin o mechanic. No guardar
el JWT en archivos compartidos:

```powershell
$api = 'https://taller-api.duckdns.org'
$loginBody = @{ login = 'admin@example.com'; password = '<contraseña>' } | ConvertTo-Json
$login = Invoke-RestMethod -Uri "$api/login-user" -Method Post `
  -ContentType 'application/json' -Body $loginBody
$token = $login.body.token
$headers = @{ Authorization = "Bearer $token" }
```

La clave pública debe responder `200`:

```powershell
Invoke-RestMethod -Uri "$api/api/push/public-key" -Headers $headers
```

La respuesta contiene `body.public_key`; nunca debe contener la privada.

Sin JWT, debe responder `401` con el envoltorio JSON habitual:

```powershell
Invoke-WebRequest -Uri "$api/api/push/public-key" -SkipHttpErrorCheck |
  Select-Object StatusCode, Content
```

`POST /api/push/subscribe` sin `endpoint` o sin `keys.p256dh`/`keys.auth` debe
responder `422`. Con JWT válido, pero sin la migración aplicada, devolverá un
error al persistir: aplicar la migración antes de probar las suscripciones.

## 3. Probar UPSERT y baja sin enviar una notificación

Esta prueba usa un endpoint ficticio de un host push admitido. No es una
suscripción funcional y no debe usarse para probar entrega; sirve para probar
solamente el endpoint y la base de datos.

```powershell
$fakeEndpoint = 'https://fcm.googleapis.com/fcm/send/backend-upsert-test'
$subscribeBody = @{
  endpoint = $fakeEndpoint
  keys = @{ p256dh = 'test-p256dh'; auth = 'test-auth-1' }
  user_agent = 'backend smoke test'
} | ConvertTo-Json -Compress
$first = Invoke-RestMethod -Uri "$api/api/push/subscribe" -Method Post `
  -Headers $headers -ContentType 'application/json' -Body $subscribeBody

$subscribeBodyObject = $subscribeBody | ConvertFrom-Json
$subscribeBodyObject.keys.auth = 'test-auth-2'
$updatedBody = $subscribeBodyObject | ConvertTo-Json -Compress
$second = Invoke-RestMethod -Uri "$api/api/push/subscribe" -Method Post `
  -Headers $headers -ContentType 'application/json' -Body $updatedBody

if ($first.body.id -ne $second.body.id) {
  throw 'UPSERT creó otra fila en vez de actualizar la existente'
}
```

Verificar en MariaDB que hay una sola fila para ese endpoint hash y que `auth`
se actualizó:

```sql
SELECT COUNT(*) AS subscription_count, MAX(auth) AS current_auth
FROM push_subscriptions
WHERE endpoint_hash = SHA2('https://fcm.googleapis.com/fcm/send/backend-upsert-test', 256);
```

Luego limpiar la fila:

```powershell
$unsubscribeBody = @{ endpoint = $fakeEndpoint } | ConvertTo-Json -Compress
$deleted = Invoke-RestMethod -Uri "$api/api/push/unsubscribe" -Method Post `
  -Headers $headers -ContentType 'application/json' -Body $unsubscribeBody
$deletedAgain = Invoke-RestMethod -Uri "$api/api/push/unsubscribe" -Method Post `
  -Headers $headers -ContentType 'application/json' -Body $unsubscribeBody

$deleted.body.deleted     # 1 en la primera baja
$deletedAgain.body.deleted # 0 en la segunda; sigue siendo HTTP 200
```

El endpoint acepta solo URLs HTTPS de servicios push conocidos. No incluir
tokens, endpoint reales ni claves de navegador en capturas o logs.

## 4. Crear una suscripción real desde el navegador

Para comprobar entrega real, el frontend debe servirse desde HTTPS (o
`localhost` en desarrollo), registrar un service worker y tener un handler
`push` que muestre la notificación. Confirmar también que el usuario otorgó
permiso para notificaciones.

En la consola del sitio frontend, con `$token` reemplazado por el JWT válido y
una vez que su service worker esté registrado:

```javascript
const api = 'https://taller-api.duckdns.org';
const token = '<JWT del usuario>';
const headers = {
  Authorization: `Bearer ${token}`,
  'Content-Type': 'application/json'
};

function base64UrlToBytes(value) {
  const padding = '='.repeat((4 - value.length % 4) % 4);
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

const keyResponse = await fetch(`${api}/api/push/public-key`, { headers });
const { body: { public_key: publicKey } } = await keyResponse.json();
const registration = await navigator.serviceWorker.ready;
let subscription = await registration.pushManager.getSubscription();

if (!subscription) {
  subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: base64UrlToBytes(publicKey)
  });
}

const subscribeResponse = await fetch(`${api}/api/push/subscribe`, {
  method: 'POST',
  headers,
  body: JSON.stringify({
    ...subscription.toJSON(),
    user_agent: navigator.userAgent
  })
});
console.log(subscribeResponse.status, await subscribeResponse.json());
```

Repetir el proceso en cada navegador/usuario que se probará. Cada navegador
debe guardar su propia suscripción.

El service worker del frontend debe manejar el evento entrante, por ejemplo:

```javascript
self.addEventListener('push', (event) => {
  const payload = event.data ? event.data.json() : {};
  event.waitUntil(self.registration.showNotification(payload.title || 'Mecaflow', {
    body: payload.body || '',
    data: { order_id: payload.order_id }
  }));
});
```

Este handler pertenece al frontend; el backend solo envía el payload.

## 5. Probar el botón de notificación global

Este endpoint está pensado para un botón explícito de prueba visible solo a
admins. Envía un mensaje fijo a todos los dispositivos con suscripción
registrada de usuarios activos con rol `admin` o `mechanic`; no acepta un
mensaje arbitrario y no inserta una fila en `alerts`.

Desde PowerShell, con un JWT de admin y después de registrar suscripciones
reales en los navegadores que se probarán:

```powershell
$testResult = Invoke-RestMethod -Uri "$api/api/push/test" -Method Post `
  -Headers $headers
$testResult
```

La respuesta informa cuántos dispositivos estaban registrados, cuántos
servicios push aceptaron el mensaje y cuántos fallaron. `accepted_by_push_service`
no confirma que la notificación ya apareció en pantalla; valida también el
permiso del navegador y el handler `push` del service worker. Un usuario sin
rol admin debe recibir `403`; una petición sin JWT debe recibir `401`.

El frontend puede llamar la misma ruta al hacer clic en el botón:

```javascript
const response = await fetch(`${api}/api/push/test`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}` }
});
const result = await response.json();
```

No ejecutar el endpoint repetidamente ni usarlo como botón de notificación
operativa: es una prueba global. El botón debe estar limitado a admins y
deshabilitarse mientras se procesa la petición.

## 6. Disparar notificaciones reales

Con la suscripción real guardada:

1. **Admin:** crear una orden nueva o realizar una operación que genere una
   alerta (por ejemplo, un cambio real de estado). Debe llegar un push a los
   admins activos suscritos.
2. **Asignación:** iniciar sesión como admin y enviar un PATCH a
   `/api/orders` con el ID de una orden activa y un `assigned_user_id` que
   agregue un mecánico suscrito:

   ```powershell
   $patchBody = @{
     id = 31
     assigned_user_id = @(7)
   } | ConvertTo-Json -Compress

   Invoke-RestMethod -Uri "$api/api/orders" -Method Patch `
     -Headers $headers -ContentType 'application/json' -Body $patchBody
   ```

   Sustituir `31` por una orden existente y `7` por un mecánico activo. Si la
   orden ya tiene una lista de asignados, incluir los existentes y el nuevo.
   Solo los mecánicos agregados en este PATCH reciben el push; el mecánico
   asignado previamente no debe recibir uno repetido.
3. Repetir el PATCH sin cambios y confirmar que no se genera otro aviso de
   asignación.
4. Cambiar la orden a `delivered` o `cancelled` en el mismo PATCH que agrega a
   un mecánico y confirmar que no se envía el push de asignación.
5. Probar decisiones de presupuesto/repuestos usando las operaciones normales
   del portal y confirmar que las alertas correspondientes generan push a
   admins suscritos.

Los envíos usan `title`, `body` (truncado a unos 200 caracteres) y `order_id`.
La operación principal se confirma antes del envío; una falla push debe quedar
en el log interno y no convertir una orden exitosa en error.

## 7. Baja, expiración y diagnóstico

- Para retirar una suscripción, llamar `POST /api/push/unsubscribe` con JWT y
  el endpoint del navegador. El frontend también puede ejecutar
  `subscription.unsubscribe()` para quitar el permiso local.
- Una suscripción revocada por el navegador puede recibir HTTP 410 del servicio
  push; al procesar ese reporte el backend elimina su fila por `endpoint_hash`.
  Un endpoint inventado no garantiza un 410 y no demuestra por sí solo la
  limpieza automática.
- Revisar logs sin imprimir secretos:

  ```powershell
  docker compose logs --tail 200 php_backend
  ```

- Si el API responde exitosamente pero no aparece una notificación, comprobar:
  permiso del navegador, service worker activo en el mismo origen, suscripción
  guardada para ese usuario, claves VAPID coincidentes y respuesta del servicio
  push en los logs.
- No regenerar las claves VAPID después de guardar suscripciones de usuarios:
  cambiar el par puede invalidar las suscripciones existentes.

## Estado de verificación

En el entorno de desarrollo se pueden validar el build Docker, autoload,
endpoints, autenticación, UPSERT y baja con una base aislada. Para certificar el
despliegue real todavía hay que confirmar que el `.env` del servidor tiene las
tres variables VAPID, aplicar la migración en la base de producción y ejecutar
la prueba desde un navegador con service worker y permiso activo.
