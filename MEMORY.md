# Estado actual
- API PHP/MySQL con CRUD dinámico y rutas explícitas para órdenes en `routes/controllers/WorkshopController.php`.
- POST/PATCH de órdenes consultan `SHOW COLUMNS FROM work_orders`, validan campos y construyen SQL con prepared statements; `checklist` es obligatorio al crear y `parts` opcional.
- `/api/orders` omite estados `cancelled` y `delivered`; `/api/orders/delivered` lista órdenes cerradas por `created_at` con paginación.
- PATCH `/api/orders/{id}/status` establece `started_at`/`finished_at` en primeras transiciones y puede guardar `withdrawal_comment`.
- GET público `/api/public/order/{token}` no debe devolver datos internos ni logs. Evidencias usan `order_evidence` y se devuelven como URLs absolutas ordenadas por carga.
- `work_orders.parts` admite IDs de texto no vacíos; las decisiones públicas validan pertenencia e idempotencia.
- La migración `20261013_add_order_media_names.sql` agrega nombres de medios a `work_orders`; cargar evidencias secuencialmente.
- Audit trail: `order_audit_log` se crea con `20261016_create_order_audit_log.sql` (aplicada en la base local); cambios y log deben confirmarse en una transacción.
- `updated_by` puede aportar el nombre (firstName requerido, lastName opcional), pero su ID debe coincidir con el usuario autenticado; la identidad/rol no se aceptan sin validar contra JWT. Si falta, usar actor de sistema.
- `GET /api/orders/{id}/audit-log` es exclusivo de admin, ordena por `id DESC` y Swagger se mantiene sincronizado.
- Login selecciona `users.last_name`, incluye `lastName` en el body y saluda con nombre y apellido en `statusText`; preservar compatibilidad de `first_name`.
- Alertas de admin: `GET /api/alerts?unread=1`, `PATCH /api/alerts` con `{id}` y `POST /api/alerts/read-all`; sin nueva tabla o columna. Tipos nuevos: `order_created`, `order_status_changed`, `part_requested`, `budget_accepted`, `budget_rejected`.
- Las alertas de órdenes se insertan después de confirmar la operación; errores se registran sin revertirla. Decisiones públicas generan tipo específico y son idempotentes.
- `work_orders.budget_accepted` figura en el contrato/frontend pero falta en `database/workshop.sql`; asumir que existe en la BD desplegada. El monto se calcula desde `budget_items`.

# Decisiones
- Mantener `work_orders` como recurso explícito; los metadatos MySQL evitan listas rígidas de columnas.
- Bloquear campos administrados por servidor y mantener tokens/credenciales fuera de respuestas públicas.
- Las escrituras de órdenes y sus eventos de auditoría deben ser atómicas para no dejar historial incompleto.
- Las alertas son best-effort y el estado leído es compartido entre administradores, de acuerdo con las seis columnas existentes de `alerts`.
- Registrar solo diferencias reales; estados entregado/cancelado usan acciones específicas y status genérico usa `status_changed`.
- Conservar `finished_at` existente en reentradas a estados terminados.
- Los errores internos se registran, pero no se exponen al cliente.

# Errores a evitar
- No omitir migraciones SQL ni actualizaciones Swagger cuando cambia el contrato.
- No confiar en MIME/nombres de archivos informados por el cliente; inspeccionar y procesar el contenido.
- No escribir contraseñas en claro en operaciones CRUD dinámicas.
- No hacer consultas N+1 para evidencias ni exponer audit trail desde portal público.
- No agregar `budget_accepted` ni otras columnas a `alerts`; resolver aparte la diferencia entre el esquema local y el desplegado de `work_orders`.
