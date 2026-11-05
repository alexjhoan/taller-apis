# Estado actual
- API PHP/MySQL con CRUD dinámico y rutas explícitas para órdenes en `routes/controllers/WorkshopController.php`.
- POST/PATCH de órdenes consultan `SHOW COLUMNS FROM work_orders`, validan campos y construyen SQL con prepared statements; `checklist` es obligatorio al crear y `parts` opcional.
- `/api/orders` omite estados `cancelled` y `delivered`; `/api/orders/delivered` lista órdenes cerradas por `created_at` con paginación.
- PATCH `/api/orders/{id}/status` establece `started_at`/`finished_at` en primeras transiciones y puede guardar `withdrawal_comment`.
- GET público `/api/public/order/{token}` no debe devolver datos internos ni logs. Evidencias usan `order_evidence` y se devuelven como URLs absolutas ordenadas por carga.
- `work_orders.parts` admite IDs de texto no vacíos; las decisiones públicas validan pertenencia e idempotencia.
- La migración `20261013_add_order_media_names.sql` agrega nombres de medios a `work_orders`; cargar evidencias secuencialmente.
- Audit trail: `order_audit_log` se crea con `20261016_create_order_audit_log.sql` (aplicada en la base local); cambios y log deben confirmarse en una transacción.
- Evidencias tienen `evidence_type` (`reception`, `work_progress`, `final`) con migración `20261027_add_order_evidence_type.sql`; subida por rol, lectura por rol y orden por tipo/fecha. `DELETE /api/orders/{id}/evidence/{evidenceId}` es solo admin.
- En auditoría de órdenes, `updated_by` puede aportar el nombre (firstName requerido, lastName opcional), pero su ID debe coincidir con el usuario autenticado; la identidad/rol no se aceptan sin validar contra JWT. Si falta, usar actor de sistema.
- `GET /api/orders/{id}/audit-log` es exclusivo de admin, ordena por `id DESC` y Swagger se mantiene sincronizado.
- Login selecciona `users.last_name`, incluye `lastName` en el body y saluda con nombre y apellido en `statusText`; preservar compatibilidad de `first_name`.
- Alertas: alertas nuevas usan `alert_recipients` por usuario; admins activos y usuarios asignados reciben, solo sus recibos se leen y `updated_by` normalizado se escribe/retorna por recibo. Históricas `targeted=0` solo visibles/marcables por admins (antes se filtraban globalmente a mecánicos). Migración `20261029_add_alert_recipient_updated_by.sql`. Asignación acepta IDs activos con rol admin/mechanic. CRUD dinámico bloquea alerts y receipts.
- Las alertas de órdenes se insertan después de confirmar la operación; errores se registran sin revertirla. Decisiones públicas generan tipo específico y son idempotentes.
- `work_orders.budget_accepted` figura en el contrato/frontend pero falta en `database/workshop.sql`; asumir que existe en la BD desplegada. El monto se calcula desde `budget_items`.
- Tabla `versions` (`id`, `version`, `created_at`) y GET público `/get-version-app`; migración `20261105_create_versions_table.sql`. La tabla se crea vacía si no se proporciona un valor inicial.

# Decisiones
- Mantener `work_orders` como recurso explícito; los metadatos MySQL evitan listas rígidas de columnas.
- Bloquear campos administrados por servidor y mantener tokens/credenciales fuera de respuestas públicas.
- Las escrituras de órdenes y sus eventos de auditoría deben ser atómicas para no dejar historial incompleto.
- Las alertas nuevas son best-effort y su inserción más destinatarios es atómica; la audiencia no viene del cliente. Mantener compatibilidad global solo para alertas históricas hasta decidir su limpieza/migración.
- El endpoint de versión es público para permitir consulta desde la app sin JWT; mantener su respuesta limitada a los registros de versiones.
- Registrar solo diferencias reales; estados entregado/cancelado usan acciones específicas y status genérico usa `status_changed`.
- Conservar `finished_at` existente en reentradas a estados terminados.
- Los errores internos se registran, pero no se exponen al cliente.

# Errores a evitar
- No omitir migraciones SQL ni actualizaciones Swagger cuando cambia el contrato.
- No confiar en MIME/nombres de archivos informados por el cliente; inspeccionar y procesar el contenido.
- No escribir contraseñas en claro en operaciones CRUD dinámicas.
- No hacer consultas N+1 para evidencias ni exponer audit trail desde portal público.
- No guardar `updated_by` de alertas sin validarlo contra el usuario autenticado; el endpoint de lectura masiva aún ignora ese campo.
