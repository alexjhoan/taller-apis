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
  "assigned_user_id": "[2,4]",
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

Campos obligatorios: `customer_name`, `customer_phone`, `vehicle_plate`, `vehicle_make`,
`vehicle_model`, `mileage`, `fuel_level`, `failure_description` y `checklist`.

`assigned_user_id` es opcional y admite un arreglo de IDs, una cadena JSON como `"[2,4]"`,
un ID entero individual por compatibilidad o `null`. Cada ID debe corresponder a un usuario
activo cuyo rol sea `admin` o `mechanic`; no se permiten IDs repetidos. Esto permite que
admins de talleres pequeños también sean asignados como responsables de trabajo. Si un
admin crea la orden con usuarios asignados, el backend genera `order_assigned` para todos
los admins activos y cada usuario asignado.
Los cambios posteriores solo los puede hacer un admin y producen la misma alerta. La
asignación define la audiencia; el frontend no envía una lista de destinatarios de alertas.

Ejemplo de PATCH de asignación:

```json
{
  "id": 25,
  "assigned_user_id": "[2,4]"
}
```

También se acepta un arreglo JSON nativo:

```json
{
  "id": 25,
  "assigned_user_id": [2, 4]
}
```

`assigned_user_id` se almacena como JSON serializado en la columna, que debe ser tipo
`TEXT`; al leer una orden, el frontend recibirá la cadena, por ejemplo `"[2,4]"`.

El backend consulta `SHOW COLUMNS FROM work_orders` y arma dinámicamente los campos y
valores del INSERT/PATCH según las columnas existentes. `images` y `videos` son columnas
administradas por la carga de evidencias y no se pueden escribir mediante POST/PATCH de
órdenes.

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
      {
        "id": 10,
        "url": "https://api.example.test/taller-apis/assets/evidence/25/20261013_180000_abcd1234.jpg",
        "evidence_type": "reception",
        "type": "photo",
        "created_at": "2026-10-13 18:00:00"
      }
    ]
  },
  "statusText": "success",
  "total": 1
}
```

Cuando el usuario es `mechanic` y `mechanic_cost_visibility` vale `0`, el backend omite `unit_price` y `supplier_cost` de cada ítem.
`evidence` contiene objetos con `id`, `url`, `evidence_type`, `type` y `created_at`, ordenados por categoría (`reception`, `work_progress`, `final`) y luego por fecha ascendente. Los roles `admin` ven todos los tipos; `mechanic` ve recepción y progreso; `reception` ve solo recepción. Los archivos legacy sin fila en `order_evidence` se clasifican como `work_progress`. El mismo campo se incluye por orden en `GET /api/orders` y `GET /api/orders/delivered`; el portal público recibe todas las categorías.

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

Roles: `admin`, `mechanic`, `reception`.

Usar `multipart/form-data`, no JSON. Enviar un solo archivo por solicitud y esperar la respuesta antes de enviar el siguiente para preservar el orden en que fueron seleccionados.

Campos requeridos:

```text
file
evidence_type
```

Valores permitidos de `evidence_type`:

```text
reception
work_progress
final
```

Permisos de carga:

```text
reception: reception
mechanic: work_progress
admin: reception, work_progress, final
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

Además de registrar la ruta, tipo de medio y `evidence_type` en `order_evidence`, cada carga agrega el nombre generado (sin la ruta) a `work_orders.images` para imágenes o `work_orders.videos` para videos. Los nombres se concatenan separados por comas y en orden de carga. Para reconstruir la ruta se combina el nombre con `assets/evidence/{id_order}/`. Ejecuta las migraciones `database/migrations/20261013_add_order_media_names.sql` y `database/migrations/20261027_add_order_evidence_type.sql` antes de habilitar estas cargas.

#### Respuesta `201`

```json
{
  "status": 201,
  "body": {
    "id": 10,
    "url": "assets/evidence/25/20261010_114759_a1b2c3d4.mp4",
    "evidence_type": "work_progress"
  },
  "statusText": "Evidence uploaded"
}
```

Campos relacionados de `order_evidence`:

```text
id
order_id
type
evidence_type
file_url
uploaded_by
created_at
```

### GET `/api/orders/{id}/evidence`

Roles: `admin`, `mechanic`, `reception`.

El parámetro opcional `?type=reception|work_progress|final` filtra una categoría; si se solicita un tipo no visible para el rol autenticado, responde `403`. Sin parámetro devuelve todos los tipos permitidos para ese rol. La respuesta incluye `evidence_type` en cada objeto y ordena por categoría (`reception`, `work_progress`, `final`) y fecha ascendente.

```json
{
  "status": 200,
  "body": [
    {
      "id": 10,
      "url": "https://api.example.test/taller-apis/assets/evidence/25/20261013_180000_abcd1234.jpg",
      "evidence_type": "reception",
      "type": "photo",
      "created_at": "2026-10-13 18:00:00"
    }
  ],
  "statusText": "success",
  "total": 1
}
```

### DELETE `/api/orders/{id}/evidence/{evidenceId}`

Solo `admin`. Elimina el registro y archivo asociados, y retira el nombre del archivo de `work_orders.images` o `work_orders.videos`. Roles distintos de admin reciben `403`.

En `work_orders`, `images` y `videos` son columnas de texto nullable que contienen los nombres de archivos procesados separados por comas. Las listas de órdenes autenticadas también aplican el filtro de categorías por rol a estos campos. El portal público devuelve todas las categorías, agrupadas por tipo y con fecha ascendente dentro de cada grupo.

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
      {
        "id": 10,
        "url": "https://api.example.test/taller-apis/assets/evidence/25/20261013_180000_abcd1234.jpg",
        "evidence_type": "reception",
        "type": "photo",
        "created_at": "2026-10-13 18:00:00"
      }
    ]
  },
  "statusText": "success",
  "total": 1
}
```

`order` se obtiene dinámicamente con todas las columnas actuales de `work_orders`, incluyendo columnas nuevas añadidas a la base. Solo se omiten `public_token` (credencial de acceso del portal) y `created_by` (dato interno de autoría). `budget` contiene las partidas y sus IDs necesarios para presentar las opciones al cliente y enviar decisiones con `POST /api/public/order/{token}/decision`. `evidence` contiene objetos con la URL absoluta y `evidence_type`; el cliente ve todas las categorías agrupadas por tipo y con fecha ascendente dentro de cada grupo.

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

## 8. Alertas

### GET `/api/alerts`

Disponible para cualquier usuario autenticado. Sin parámetros, devuelve hasta 100 alertas
visibles para ese usuario. Las alertas nuevas solo se entregan a los admins activos y al
mecánico o admin activo asignado a la orden. El servidor obtiene la identidad del token Bearer; no
se debe enviar `user_id` ni una lista de usuarios en la query.

Agrega `?unread=1` para recibir pendientes para el usuario autenticado, ordenadas por fecha
e ID descendentes, con un máximo de 50. Agrega `?read=1` para recibir las leídas por ese
usuario, ordenadas por `read_at DESC` e ID descendente, con un máximo de 50. Usa `read` o
`unread`, no ambos a la vez. Las alertas anteriores a la migración conservan temporalmente
su `read_at` global, pero solo los admins pueden verlas; los mecánicos solo reciben alertas
que tengan un recibo dirigido a su usuario.

No usar `POST /api/alerts`: la creación es interna del backend para que los destinatarios
se resuelvan usando la asignación confiable de la orden. El endpoint responde `405`.

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
      "updated_by": null,
      "created_at": "2026-10-23 09:41:00"
    }
  ],
  "statusText": "success"
}
```

### PATCH `/api/alerts`

El payload `{ "id": 7 }` marca la alerta como leída para el usuario autenticado y devuelve
la fila con el `read_at` correspondiente a ese usuario. No enviar `user_id`: el backend lo
obtiene del token. El cliente no debe enviar `read_at`; se acepta por compatibilidad y se
ignora. `updated_by` también se acepta por compatibilidad y puede omitirse. Si se envía, su ID debe
coincidir con el token; el backend guarda la identidad normalizada consultada desde `users`.
La respuesta incluye `updated_by` para identificar al lector. No se admiten otros campos.

Para una alerta nueva, únicamente uno de sus destinatarios puede marcarla como leída y la
lectura no afecta a los demás. Las alertas históricas sin destinatarios mantienen
temporalmente la lectura global; solo un admin puede marcarlas durante esta transición.
Si el ID no existe o el usuario no es destinatario, la API responde `404`.

```json
{
  "id": 7
}
```

Respuesta `403` si un usuario que no es admin intenta marcar una alerta histórica global,
o si el ID de `updated_by` no coincide con el usuario autenticado. Respuesta `422` si falta
el ID, no es numérico, `updated_by` no es una cadena JSON válida o el payload incluye
campos no admitidos.

### POST `/api/alerts/read-all`

Disponible para cualquier usuario autenticado. Acepta cuerpo vacío, `{}` o `updated_by`
como una cadena JSON serializada por compatibilidad; el backend ignora `updated_by`.
Marca las alertas nuevas destinadas a ese usuario. Si el usuario es admin, también marca
las alertas históricas globales pendientes, que es el comportamiento temporal anterior.
Devuelve la cantidad de recibos/alertas cambiados. Una segunda llamada sin nuevas alertas
devuelve `updated: 0`. No se admiten otros campos.

```json
{
  "updated_by": "{\"id\":2,\"firstName\":\"admin\",\"lastName\":\"last_name\",\"role\":\"admin\"}"
}
```

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
updated_by
created_at
```

`read_at` refleja la lectura del usuario autenticado para alertas nuevas; para alertas
históricas sigue siendo global durante la transición. `updated_by` se guarda por recibo
individual en las alertas nuevas y sigue en la fila global para las históricas. Las nuevas
alertas se comparten entre admins activos y se entregan a los
admins/mecánicos activos asignados a la orden, si existen; usuarios no asignados con rol
mechanic no pueden verlas.
En recibos creados antes de agregar `alert_recipients.updated_by`, el campo puede ser
`null` hasta que ese usuario vuelva a marcar la alerta como leída; no se puede reconstruir
quién leyó previamente un aviso compartido.
Los tipos generados incluyen `order_created`, `order_assigned`, `order_status_changed`,
`part_requested`, `budget_accepted` y `budget_rejected`. Las filas históricas `customer_decision` siguen
siendo válidas. Un error al guardar una alerta se registra en el log, pero no revierte la
operación de la orden.

Reglas de emisión:

- Una orden creada por `mechanic` produce `order_created`.
- Una orden creada por `admin` con uno o varios mecánicos activos asignados, o cuya asignación cambia
  por un PATCH de admin, produce `order_assigned`; una orden admin sin asignación no lo hace.
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
# Lista las alertas pendientes visibles para el usuario autenticado.
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

# Marca todas las alertas visibles del usuario; repetir devuelve updated: 0.
curl -i -X POST "$BASE_URL/api/alerts/read-all" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'

# Los mechanics pueden listar y marcar las alertas nuevas destinadas a ellos.
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

# Crear una orden como mechanic produce order_created; como admin, incluir
# assigned_user_id con IDs de usuarios activos admin/mechanic para generar order_assigned.
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

## Notificaciones Push Web

Las notificaciones push requieren que el servidor defina `VAPID_PUBLIC_KEY`,
`VAPID_PRIVATE_KEY` y `VAPID_SUBJECT` en su entorno. La clave privada nunca se
devuelve al cliente. Con Docker Compose, estas variables se leen del archivo
`.env` del servidor; generarlas con `npx web-push generate-vapid-keys` y no
guardar el archivo `.env` en el repositorio:

```dotenv
VAPID_PUBLIC_KEY=...
VAPID_PRIVATE_KEY=...
VAPID_SUBJECT=mailto:contacto@taller.com
```

Aplicar `database/migrations/20261106_create_push_subscriptions.sql` antes de
usar estos endpoints. Solo usuarios autenticados activos con rol `admin` o
`mechanic` pueden administrar sus suscripciones.

### GET `/api/push/public-key`

Requiere JWT. Devuelve únicamente la clave pública:

```json
{
  "status": 200,
  "body": { "public_key": "BExxxxx..." },
  "statusText": "success"
}
```

### POST `/api/push/subscribe`

Requiere JWT y JSON. La suscripción se crea o actualiza por el hash SHA-256
del endpoint, así que volver a suscribir el mismo navegador no crea otra fila.

```json
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/AAA...",
  "keys": {
    "p256dh": "BExxx...",
    "auth": "yyy..."
  },
  "user_agent": "Mozilla/5.0 ..."
}
```

`user_agent` es opcional. Respuesta:

```json
{
  "status": 200,
  "body": { "id": 1, "saved": true },
  "statusText": "success"
}
```

### POST `/api/push/unsubscribe`

Requiere JWT y JSON:

```json
{ "endpoint": "https://fcm.googleapis.com/fcm/send/AAA..." }
```

El endpoint se elimina de forma idempotente y `deleted` vale `0` si no había
una suscripción.

El servidor envía notificaciones best-effort a admins cuando crea alertas de
órdenes, decisiones de presupuesto, repuestos o cambios de estado. Cuando un
PATCH agrega mecánicos a `assigned_user_id`, solo los mecánicos nuevos reciben
el aviso de asignación; no se envía si la orden queda en estado `delivered` o
`cancelled`. Un fallo del servicio push se registra internamente y no revierte
la operación principal.

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
evidence_type
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
updated_by
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
