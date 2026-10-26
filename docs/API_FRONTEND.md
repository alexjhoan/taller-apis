# API REST del Taller Mecánico

Documento de integración para frontend.

## 1. Configuración

```text
Content-Type: application/json
Base URL: https://TU-DOMINIO
```

Las rutas que empiezan por `/api` conservan ese prefijo. Por ejemplo:

```text
https://TU-DOMINIO/api/orders
```

Las rutas públicas no requieren autenticación. Las rutas internas requieren:

```http
Authorization: Bearer JWT_TOKEN
```

Roles disponibles en la base de datos:

- `admin`
- `mechanic`

Todas las respuestas JSON siguen esta estructura general:

```json
{
  "status": 200,
  "body": {},
  "statusText": "success"
}
```

Los errores mantienen la misma estructura:

```json
{
  "status": 422,
  "body": [],
  "statusText": "Mensaje del error"
}
```

## 2. Autenticación

### POST `/login-user`

Ruta pública para administrador y mecánico.

#### Payload

```json
{
  "login": "admin@example.com",
  "password": "ContraseñaSegura123"
}
```

`login` acepta tanto `users.email` como `users.nickname`. Por compatibilidad, también se acepta el campo `email`, pero se recomienda usar siempre `login`.

#### Respuesta `200`

```json
{
  "status": 200,
  "body": {
    "id": 1,
    "token": "JWT_TOKEN",
    "first_name": "Admin"
    ,"nickname": "admin"
    ,"role": "admin"
  },
  "statusText": "Bienvenido Admin"
}
```

Guardar `body.token` y enviarlo en el header `Authorization` para las rutas internas.

## 3. CRUD dinámico por tabla

El backend conserva un CRUD genérico. Las claves del payload deben tener exactamente el mismo nombre que las columnas MySQL.

### GET `/{table}`

Ejemplos:

```text
GET /users
GET /work_orders
GET /budget_items
GET /order_evidence
```

Requiere JWT cuando se consulta información interna.

#### Respuesta de colección

```json
{
  "status": 200,
  "body": [
    {
      "id": 1,
      "customer_name": "Juan Perez",
      "vehicle_plate": "ABC123",
      "status": "received"
    }
  ],
  "statusText": "success",
  "total": 1
}
```

### GET `/{table}?id={id}`

Ejemplo:

```text
GET /work_orders?id=1
```

#### Respuesta individual

```json
{
  "status": 200,
  "body": {
    "id": 1,
    "public_token": "TOKEN_PUBLICO",
    "customer_name": "Juan Perez",
    "vehicle_plate": "ABC123",
    "status": "received"
  },
  "statusText": "success",
  "total": 1
}
```

### POST `/{table}`

El payload se recorre dinámicamente. Las claves deben coincidir con las columnas de la tabla.

Ejemplo para `work_orders`:

```json
{
  "public_token": "TOKEN_GENERADO_POR_EL_FRONT_SOLO_SI_USA_CRUD_DIRECTO",
  "customer_name": "Juan Perez",
  "customer_phone": "+573001112233",
  "customer_email": "juan@example.com",
  "assigned_user_id": 3,
  "vehicle_plate": "ABC123",
  "vehicle_make": "Toyota",
  "vehicle_model": "Corolla",
  "vehicle_year": 2020,
  "images": "20261013_180000_abcd1234.jpg",
  "videos": "20261013_180100_efgh5678.mp4",
  "mileage": 85000,
  "fuel_level": "1/2",
  "failure_description": "El vehículo vibra al frenar",
  "status": "received",
  "created_by": 1
}
```

> Para la recepción normal se recomienda usar `POST /api/orders`, porque genera `public_token` automáticamente y valida los campos requeridos.

> El CRUD genérico está pensado para operaciones simples de tablas. Para órdenes, evidencias, presupuestos, decisiones y configuraciones se deben usar las rutas explícitas documentadas en las siguientes secciones.

### PATCH `/{table}`

El campo `id` identifica el registro. Los demás campos se actualizan dinámicamente.

Ejemplo:

```json
{
  "id": 1,
  "status": "diagnosing",
  "failure_description": "Se detecta desgaste en pastillas delanteras"
}
```

### DELETE `/{table}?column={column}&id={value}`

Ejemplo:

```text
DELETE /budget_items?column=id&id=5
```

## 4. Órdenes de trabajo

Tabla principal: `work_orders`.

### POST `/api/orders`

Roles: `admin`, `mechanic`.

Genera automáticamente `public_token` de 48 caracteres hexadecimales.

#### Payload

```json
{
  "customer_name": "Juan Perez",
  "customer_phone": "+573001112233",
  "customer_email": "juan@example.com",
  "vehicle_plate": "ABC123",
  "vehicle_make": "Toyota",
  "vehicle_model": "Corolla",
  "vehicle_year": 2020,
  "mileage": 85000,
  "fuel_level": "1/2",
  "failure_description": "El vehículo vibra al frenar",
  "checklist": []
}
```

Campos obligatorios:

```

`assigned_user_id` es opcional y admite un ID entero o `null`. El backend consulta `SHOW COLUMNS FROM work_orders` y arma dinámicamente los campos y valores del INSERT/PATCH según las columnas existentes; al agregar o cambiar columnas compatibles no se necesita agregar una lista de campos en el controlador. La base de datos debe tener el nombre exacto de cada clave enviada. `images` y `videos` son columnas administradas por la carga de evidencias y no se pueden escribir mediante POST/PATCH de órdenes.
customer_name
customer_phone
vehicle_plate
vehicle_make
vehicle_model
mileage
fuel_level
failure_description
```

#### Respuesta `201`

```json
{
  "status": 201,
  "body": {
    "id": 25,
    "token": "a3f5...TOKEN_DE_48_CARACTERES"
  },
  "statusText": "Order created"
}
```

El valor `body.token` corresponde a la columna `work_orders.public_token`.

### GET `/api/orders/{id}`

Roles: `admin`, `mechanic`.

#### Respuesta `200`

```json
{
  "status": 200,
  "body": {
    "order": {
      "id": 25,
      "public_token": "a3f5...",
      "customer_name": "Juan Perez",
      "customer_phone": "+573001112233",
      "customer_email": "juan@example.com",
      "vehicle_plate": "ABC123",
      "vehicle_make": "Toyota",
      "vehicle_model": "Corolla",
      "vehicle_year": 2020,
      "mileage": 85000,
      "fuel_level": "1/2",
      "failure_description": "El vehículo vibra al frenar",
      "status": "received",
      "started_at": null,
      "finished_at": null,
      "withdrawal_comment": null,
      "created_by": 1,
      "created_at": "2026-09-22 10:00:00",
      "updated_at": "2026-09-22 10:00:00"
    },
    "budget": [],
    "evidence": [
      "https://api.example.test/taller-apis/assets/evidence/25/20261013_180000_abcd1234.jpg",
      "https://api.example.test/taller-apis/assets/evidence/25/20261013_180100_efgh5678.mp4"
    ]
  },
  "statusText": "success",
  "total": 1
}
```

Cuando el usuario es `mechanic` y `mechanic_cost_visibility` vale `0`, el backend omite `unit_price` y `supplier_cost` de cada ítem.
`evidence` es un arreglo de strings con las URLs absolutas de todas las imágenes y videos procesados, ordenadas por `id` ascendente cuando tienen registro en `order_evidence`. También se incluyen archivos encontrados en `work_orders.images` y `work_orders.videos` que todavía no tengan registro de evidencia. El mismo campo se incluye por cada orden en `GET /api/orders` y `GET /api/orders/delivered`; el GET público por token también lo devuelve dentro de `body.evidence`.

### GET `/api/orders/{id}/send-email`

Roles: `admin`, `mechanic`.

Envía al cliente el resumen de la orden por correo. El ID se obtiene de la ruta y no se requiere body. Se puede enviar un mensaje adicional opcional mediante `?prompt=...` (máximo 1000 bytes).

```text
GET /api/orders/25/send-email?prompt=Actualizamos%20el%20estado%20de%20tu%20vehiculo
Authorization: Bearer <token>
```

La respuesta `200` confirma el envío. Esta operación produce un efecto secundario: cada solicitud vuelve a enviar el correo; no debe invocarse automáticamente como una consulta de lectura.

### PATCH `/api/orders/{id}/status`

Roles: `admin`, `mechanic`.

#### Payload

```json
{
  "status": "in_progress"
}
```

Valores válidos para `work_orders.status`:

```text
received
diagnosing
awaiting_approval
approved
in_progress
ready
delivered
cancelled
```

Al usar `in_progress`, se registra automáticamente `started_at` si todavía está vacío. Al usar `ready` o `delivered`, se registra `finished_at`.

#### Respuesta `200`

```json
{
  "status": 200,
  "body": {
    "updated": true
  },
  "statusText": "Order status updated"
}
```

### POST `/api/orders/{id}/withdrawal-comment`

Roles: `admin`, `mechanic`.

Marca la orden como `delivered`, registra `finished_at` si está vacío y guarda el comentario en `withdrawal_comment`.

#### Payload

```json
{
  "comment": "Vehículo retirado por el cliente. Se explicaron las reparaciones realizadas."
}
```

#### Respuesta `200`

```json
{
  "status": 200,
  "body": [],
  "statusText": "Withdrawal registered"
}
```

## 5. Evidencias

Tabla: `order_evidence`.

### POST `/api/orders/{id}/evidence`

Roles: `admin`, `mechanic`.

Usar `multipart/form-data`, no JSON. Enviar un solo archivo por solicitud y esperar la respuesta antes de enviar el siguiente para preservar el orden en que fueron seleccionados.

Campo requerido:

```text
file
```

Tipos permitidos:

```text
image/jpeg
image/png
image/webp
video/mp4
video/quicktime
```

Tamaño máximo de entrada: `50 MB` por archivo. Las imágenes originales deben tener como máximo `40 megapíxeles` para limitar el consumo de memoria durante el procesamiento.

Las imágenes JPEG, PNG y WebP se normalizan a JPG, se limitan a un máximo de `1000 px` en su lado mayor manteniendo la proporción y se comprimen. Las áreas transparentes se convierten con fondo blanco. Los videos MP4 y QuickTime/MOV se transcodifican a MP4/H.264, con un máximo de `1280 px` en su lado mayor, conservando proporción y audio. La compresión de video depende de su duración y contenido.

Los archivos procesados se guardan bajo `assets/evidence/{id_order}/` con un nombre de fecha/hora del servidor (`YYYYMMDD_HHMMSS`) y un sufijo aleatorio para evitar colisiones en el mismo segundo. El servidor requiere FFmpeg accesible por PATH o mediante la variable de entorno `FFMPEG_BINARY`.

Además de registrar la ruta y tipo en `order_evidence`, cada carga agrega el nombre generado (sin la ruta) a `work_orders.images` para imágenes o `work_orders.videos` para videos. Los nombres se concatenan separados por comas y en orden de carga. Para reconstruir la ruta se combina el nombre con `assets/evidence/{id_order}/`. Ejecuta la migración `database/migrations/20261013_add_order_media_names.sql` antes de habilitar estas cargas.

#### Respuesta `201`

```json
{
  "status": 201,
  "body": {
    "id": 10,
    "url": "assets/evidence/25/20261010_114759_a1b2c3d4.mp4"
  },
  "statusText": "Evidence uploaded"
}
```

Campos relacionados de `order_evidence`:

```text
id
order_id
type
file_url
uploaded_by
created_at
```

En `work_orders`, `images` y `videos` son columnas de texto nullable que contienen los nombres de archivos procesados separados por comas. Las respuestas GET de órdenes incluyen estos campos dentro de `order`. Para el frontend, `evidence` expone únicamente las URLs absolutas (strings); `order_evidence` conserva internamente las rutas y metadatos de cada archivo.

El portal público devuelve `evidence` en orden de carga ascendente por `id`.

## 6. Presupuestos

Tabla: `budget_items`.

### POST `/api/orders/{id}/budget-items`

Rol: `admin`.

#### Payload

```json
{
  "type": "part",
  "description": "Pastillas de freno delanteras",
  "quantity": 1,
  "unit_price": 180000,
  "supplier_cost": 120000
}
```

Valores válidos para `type`:

```text
labor
part
```

#### Respuesta `201`

```json
{
  "status": 201,
  "body": {
    "id": 35
  },
  "statusText": "Budget item created"
}
```

Campos de `budget_items`:

```text
id
order_id
type
description
quantity
unit_price
supplier_cost
customer_decision
created_at
```

`customer_decision` comienza como `pending` y puede cambiar a `accepted` o `rejected` desde el portal público.

### PATCH `/api/settings/cost-visibility`

Rol: `admin`.

Actualiza `app_settings.setting_key = mechanic_cost_visibility`.

#### Payload

```json
{
  "visible": true
}
```

#### Respuesta `200`

```json
{
  "status": 200,
  "body": {
    "visible": true
  },
  "statusText": "Setting updated"
}
```

## 7. Portal público del cliente

No requiere JWT. El acceso se realiza mediante `work_orders.public_token`.

### GET `/api/public/order/{token}`

#### Respuesta `200`

```json
{
  "status": 200,
  "body": {
    "order": {
      "id": 25,
      "customer_name": "Juan Perez",
      "customer_phone": "+573001112233",
      "customer_email": "juan@example.com",
      "assigned_user_id": 3,
      "vehicle_plate": "ABC123",
      "vehicle_make": "Toyota",
      "vehicle_model": "Corolla",
      "vehicle_year": 2020,
      "vehicle_color": "Azul",
      "vehicle_details": null,
      "mileage": 85000,
      "fuel_level": "1/2",
      "failure_description": "El vehículo vibra al frenar",
      "parts": [],
      "checklist": [],
      "images": "20261013_180000_abcd1234.jpg",
      "videos": "20261013_180100_efgh5678.mp4",
      "status": "in_progress",
      "started_at": "2026-09-22 11:00:00",
      "finished_at": null,
      "withdrawal_comment": null,
      "created_at": "2026-09-22 10:00:00",
      "updated_at": "2026-09-22 10:00:00"
    },
    "budget": [
      {
        "id": 35,
        "type": "part",
        "description": "Pastillas de freno delanteras",
        "quantity": "1.00",
        "unit_price": "180000.00",
        "customer_decision": "pending",
        "created_at": "2026-09-22 10:15:00"
      }
    ],
    "evidence": [
      "https://api.example.test/taller-apis/assets/evidence/25/20261013_180000_abcd1234.jpg",
      "https://api.example.test/taller-apis/assets/evidence/25/20261013_180100_efgh5678.mp4"
    ]
  },
  "statusText": "success",
  "total": 1
}
```

`order` se obtiene dinámicamente con todas las columnas actuales de `work_orders`, incluyendo columnas nuevas añadidas a la base. Solo se omiten `public_token` (credencial de acceso del portal) y `created_by` (dato interno de autoría). `budget` contiene las partidas y sus IDs necesarios para presentar las opciones al cliente y enviar decisiones con `POST /api/public/order/{token}/decision`. `evidence` contiene un arreglo de strings con las URLs absolutas de todas las fotos/videos procesados de la orden, en orden de carga.

### PATCH `/api/public/order/{token}`

Permite actualizar desde el portal columnas existentes de `work_orders` cuyo nombre empiece por `budget_`, y decisiones individuales de `parts`. El backend obtiene columnas/tipos de la base de datos y arma el `UPDATE` dinámicamente. `parts` solo permite cambiar `approvalStatus` en elementos ya existentes de la orden; no reemplaza la lista ni permite editar otros campos. El token identifica la orden; no se envía un ID separado.

#### Payload

```json
{
  "budget_accepted": 1,
  "parts": [
    {
      "id": "7f6f5c2a-4129-4c79-9248-cc57f8d12201",
      "decision": "accepted"
    }
  ]
}
```

`budget_accepted` admite `0` o `1` (también boolean `false`/`true`). `parts` es opcional y debe ser una lista no vacía de IDs UUID existentes en `work_orders.parts`; cada `decision` admite únicamente `accepted` o `rejected`. No se permiten IDs repetidos. Si un ID no pertenece a la orden del token, la solicitud completa se revierte. Las decisiones repetidas son idempotentes; al cambiar una decisión se genera una alerta para el administrador.

La respuesta `200` contiene el ID de la orden y los campos recibidos:

```json
{
  "status": 200,
  "body": {
    "id": 2,
    "updated_fields": ["budget_accepted", "parts"]
  },
  "statusText": "Public order fields updated",
  "total": 1
}
```

Se rechazan campos que no existan en la tabla o no usen el prefijo `budget_`, salvo `parts` con el formato limitado anterior, para evitar que el token público permita modificar el resto de la orden. También puede enviarse decisiones de partidas mediante `POST /api/public/order/{token}/decision`.

### POST `/api/public/order/{token}/decision`

No requiere JWT.

El cliente debe enviar la decisión de cada ítem en el arreglo `items`.

#### Payload

```json
{
  "items": [
    {
      "id": 35,
      "decision": "accepted"
    },
    {
      "id": "7f6f5c2a-4129-4c79-9248-cc57f8d12201",
      "decision": "rejected"
    }
  ]
}
```

Valores permitidos para `decision`:

```text
accepted
rejected
```

`id` admite un entero o string no vacío. Los IDs numéricos positivos que identifican registros de `budget_items` se procesan como partidas de presupuesto; otros valores de texto identifican elementos existentes en `work_orders.parts` (por ejemplo, UUIDs, claves externas o IDs personalizados), y su estado se guarda en `parts[].approvalStatus`. Todos los IDs deben ser únicos dentro de la solicitud. La operación valida que cada partida pertenezca a la orden del token y actualiza todas las decisiones dentro de una transacción: si algún ID no corresponde a esa orden, no se aplica ningún cambio. Cada decisión debe ser `accepted` o `rejected` (`pending` es el estado inicial, no una decisión enviada por el cliente). Si la misma decisión ya estaba guardada, la solicitud es idempotente. Solo cuando cambia una o más partidas se crea una alerta para el administrador.

#### Respuesta `200`

```json
{
  "status": 200,
  "body": {
    "updated_count": 2,
    "notified": true
  },
  "statusText": "Decision saved"
}
```

`updated_count` indica cuántas partidas cambiaron. `notified` es `false` cuando todas ya tenían la decisión solicitada.

## 8. Alertas del administrador

### GET `/api/alerts`

Rol: `admin`.

Sin parámetros, conserva la lista actual de hasta 100 alertas. Agrega `?unread=1` para
recibir solo alertas pendientes (`read_at IS NULL`), ordenadas por fecha e ID descendentes,
con un máximo de 50.

#### Respuesta `200`

```json
{
  "status": 200,
  "body": [
    {
      "id": "12",
      "order_id": "47",
      "type": "customer_decision",
      "message": "El cliente actualizo la decision del presupuesto",
      "read_at": null,
      "created_at": "2026-10-23 09:41:00"
    }
  ],
  "statusText": "success"
}
```

### PATCH `/api/alerts`

Rol: `admin`. El payload `{ "id": 7 }` marca la alerta como leída y devuelve la fila
actualizada. El backend asigna `read_at`; el cliente no debe enviar ese timestamp. Si ya
estaba leída, la misma fila se devuelve sin cambiar su fecha. Un `read_at` recibido en el
payload se ignora. Los payloads genéricos existentes con otros campos siguen usando el
PATCH dinámico de `alerts`.

```json
{ "id": 7 }
```

Respuestas: `404` si la alerta no existe, `422` si falta el ID o no es numérico.

### POST `/api/alerts/read-all`

Rol: `admin`. Acepta cuerpo vacío o `{}`; marca todas las alertas pendientes y devuelve
cuántas filas cambiaron. Una segunda llamada sin nuevas alertas devuelve `updated: 0`.

```json
{
  "status": 200,
  "body": { "updated": 3 },
  "statusText": "success"
}
```

Campos de `alerts`:

```text
id
order_id
type
message
read_at
created_at
```

Las alertas se comparten entre administradores; no se crean tablas ni columnas nuevas.
Los tipos generados incluyen `order_created`, `order_status_changed`, `part_requested`,
`budget_accepted` y `budget_rejected`. Las filas históricas `customer_decision` siguen
siendo válidas. Un error al guardar una alerta se registra en el log, pero no revierte la
operación de la orden.

Reglas de emisión:

- Una orden creada por `mechanic` produce `order_created`; una creada por `admin` no.
- Una transición real de estado produce `order_status_changed`.
- Un repuesto nuevo con `requestedByTechnician: true` y `approvalStatus: "pending"`
  produce `part_requested`; mantenerlo pendiente en ediciones posteriores no repite la alerta.
- Decisiones públicas que cambian producen `budget_rejected` si hay ítems rechazados; si
  todos los enviados fueron aceptados, producen `budget_accepted`. El monto se calcula
  sumando `quantity * unit_price` de `budget_items`.
- Un cambio `budget_accepted: 0 → 1` también genera `budget_accepted`, evitando duplicar
  una alerta pendiente que ya haya generado el portal. Este campo solo se acepta si existe
  en el esquema desplegado.

#### Ejemplos `curl`

```sh
# Lista de pendientes para la campana (solo admin).
curl -i "$BASE_URL/api/alerts?unread=1" \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Compatibilidad: lista normal de alertas, incluidas las históricas.
curl -i "$BASE_URL/api/alerts" \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Marca una alerta; repetir esta llamada no cambia read_at.
curl -i -X PATCH "$BASE_URL/api/alerts" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":7}'

# read_at enviado por el cliente se ignora.
curl -i -X PATCH "$BASE_URL/api/alerts" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":7,"read_at":"2000-01-01 00:00:00"}'

# Marca todas las pendientes; repetir devuelve updated: 0.
curl -i -X POST "$BASE_URL/api/alerts/read-all" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'

# Un usuario autenticado con rol mechanic debe recibir 403 en estas rutas.
curl -i "$BASE_URL/api/alerts?unread=1" \
  -H "Authorization: Bearer $MECHANIC_TOKEN"
curl -i -X PATCH "$BASE_URL/api/alerts" \
  -H "Authorization: Bearer $MECHANIC_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":7}'
curl -i -X POST "$BASE_URL/api/alerts/read-all" \
  -H "Authorization: Bearer $MECHANIC_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'

# Casos de validación: reemplaza 7 por un ID inexistente o elimina id para comprobar 404/422.
curl -i -X PATCH "$BASE_URL/api/alerts" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
curl -i -X PATCH "$BASE_URL/api/alerts" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":999999999}'
curl -i "$BASE_URL/api/alerts?unread=1"

# Crear una orden como mechanic produce order_created; como admin no produce alerta.
curl -i -X POST "$BASE_URL/api/orders" \
  -H "Authorization: Bearer $MECHANIC_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"customer_name":"Cliente de prueba","customer_phone":"3000000000","vehicle_plate":"ABC123","vehicle_make":"Marca","vehicle_model":"Modelo","mileage":1000,"fuel_level":"1/2","failure_description":"Prueba","checklist":[]}'

# Agregar un repuesto solicitado por mecánico; repetirlo o editar mileage no duplica alertas.
curl -i -X PATCH "$BASE_URL/api/orders" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":25,"parts":[{"id":"part-test-1","name":"Filtro de aceite","quantity":1,"unit_price":10000,"approvalStatus":"pending","requestedByTechnician":true}]}'
curl -i -X PATCH "$BASE_URL/api/orders" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":25,"mileage":1001}'

# Cambiar estado produce order_status_changed; repetir el mismo estado no duplica.
curl -i -X PATCH "$BASE_URL/api/orders" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":25,"status":"diagnosing"}'

# Las decisiones públicas producen el tipo específico una sola vez cuando cambian.
curl -i -X POST "$BASE_URL/api/public/order/$PUBLIC_TOKEN/decision" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"id":35,"decision":"accepted"}]}'
curl -i -X POST "$BASE_URL/api/public/order/$PUBLIC_TOKEN/decision" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"id":36,"decision":"rejected"}]}'

# budget_accepted es condicional al esquema desplegado; el flujo completo no agrega columnas.
curl -i -X PATCH "$BASE_URL/api/orders" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"id":25,"budget_accepted":1}'
```

## 9. WhatsApp

### GET `/api/orders/{id}/whatsapp-url`

Roles: `admin`, `mechanic`.

Genera una URL para abrir WhatsApp con el teléfono de `work_orders.customer_phone` y el enlace público basado en `work_orders.public_token`.

#### Respuesta `200`

```json
{
  "status": 200,
  "body": {
    "url": "https://wa.me/573001112233?text=Consulta%20el%20estado%20de%20tu%20veh%C3%ADculo%3A%20https%3A%2F%2Ffrontend.example.com%2Forder%2Fa3f5..."
  },
  "statusText": "success"
}
```

## 10. Campos de base de datos

### `users`

```text
id
first_name
last_name
nickname
email
password
phone
role
active
user_token
exp_token
created_at
```

El frontend nunca debe mostrar ni solicitar `password`, `user_token` o `exp_token` en consultas normales.

### `work_orders`

```text
id
public_token
customer_name
customer_phone
customer_email
vehicle_plate
vehicle_make
vehicle_model
vehicle_year
mileage
fuel_level
failure_description
images
videos
status
started_at
finished_at
withdrawal_comment
created_by
created_at
updated_at
```

### `order_evidence`

```text
id
order_id
type
file_url
uploaded_by
created_at
```

### `budget_items`

```text
id
order_id
type
description
quantity
unit_price
supplier_cost
customer_decision
created_at
```

### `app_settings`

```text
setting_key
setting_value
updated_at
```

### `alerts`

```text
id
order_id
type
message
read_at
created_at
```

## 11. Errores habituales

| HTTP | Significado |
|---:|---|
| `401` | JWT ausente, inválido o expirado |
| `403` | El rol no tiene permiso para la operación |
| `404` | Orden o registro no encontrado |
| `415` | Content-Type no soportado |
| `422` | Payload inválido o campo requerido ausente |
| `500` | Error interno o de base de datos |

## 12. Regla de integración

Para el CRUD genérico, usar siempre las columnas reales de la base de datos. No transformar nombres como:

```text
customer_name -> customerName
vehicle_plate -> vehiclePlate
unit_price -> unitPrice
```

El frontend debe enviar y leer los nombres originales:

```text
customer_name
vehicle_plate
unit_price
```
