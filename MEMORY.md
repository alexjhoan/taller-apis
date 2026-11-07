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
- Evidencias se guardan en `assets/evidence/{orderId}`. Docker prepara esa ruta como escribible para `www-data`; Compose la monta en el volumen nombrado `backend_uploads` para conservar archivos al recrear contenedores.
- Push Web usa `PushController`, `PushSender` y `push_subscriptions`; Compose inyecta `VAPID_*` desde el entorno, y `database/migrations/20261106_create_push_subscriptions.sql` debe aplicarse. Admins reciben push por alertas; en PATCH de asignación solo mecánicos nuevos, excepto órdenes entregadas/canceladas. `POST /api/push/test` está restringido a admins; acepta `user_ids`, `title`, `body` y `order_id` opcional para cuentas activas, ignora `updated_by`, o sin payload hace broadcast de prueba; no crea alertas persistentes.
- Para diagnosticar `POST /api/push/test` HTTP 500, revisar logs del backend: configuración VAPID ausente devuelve 503, mientras que errores internos se ocultan al cliente. Docker Compose carga `.env` y pasa `VAPID_*`; Apache/XAMPP no carga el `.env` del proyecto automáticamente.
- Si `push_subscriptions` no existe, los SELECT de `PushSender` en `/api/push/test` fallan y el controlador devuelve 500; aplicar `database/migrations/20261106_create_push_subscriptions.sql`. Crear la tabla no registra dispositivos: cada navegador debe suscribirse vía `/api/push/subscribe`.
- La subida de evidencias registra clase, archivo y línea de excepciones en el log. El detalle se agrega al `statusText` solo cuando `DISPLAY_ERRORS` está activado en `config.local.php`; en producción se mantiene el mensaje genérico.
- Evidencias acepta imagen/video según FFprobe en vez de una lista fija de MIME; convierte fotos a JPG (máx. 1000 px, 40 MP de entrada) y videos a MP4/H.264 (máx. 1280 px), verificando salida. El Dockerfile instala FFmpeg/FFprobe; despliegues no Docker deben tener ambos binarios en PATH o configurar `FFMPEG_BINARY` y `FFPROBE_BINARY`.
- `minishlink/web-push` v11 encola envíos con `queueNotification()` y los despacha con `flush()`; `vendor/` está ignorado y no debe versionarse.
- El Dockerfile debe generar `vendor/autoload.php` ejecutando `composer install` con `composer.lock`; `.dockerignore` excluye `vendor/` del contexto y Git no debe rastrear librerías vendorizadas.
- `docs/WEB_PUSH_TESTING.md` describe configuración, endpoints, suscripción de navegador y pruebas de triggers. `.env`/`.env.*` se ignoran; `.env.example` es el único ejemplo seguro para versionar. Las pruebas locales no certifican las claves, migración ni entrega push en producción.

# Decisiones
- Mantener `work_orders` como recurso explícito; los metadatos MySQL evitan listas rígidas de columnas.
- Bloquear campos administrados por servidor y mantener tokens/credenciales fuera de respuestas públicas.
- Las escrituras de órdenes y sus eventos de auditoría deben ser atómicas para no dejar historial incompleto.
- Las alertas nuevas son best-effort y su inserción más destinatarios es atómica; la audiencia no viene del cliente. Mantener compatibilidad global solo para alertas históricas hasta decidir su limpieza/migración.
- El endpoint de versión es público para permitir consulta desde la app sin JWT; mantener su respuesta limitada a los registros de versiones.
- Registrar solo diferencias reales; estados entregado/cancelado usan acciones específicas y status genérico usa `status_changed`.
- Conservar `finished_at` existente en reentradas a estados terminados.
- Los errores internos se registran, pero no se exponen al cliente.
- Persistir evidencias en un volumen montado sobre `assets/evidence`, la ruta usada por subida y lectura, en lugar de crear una carpeta `uploads` distinta.
- Mantener Web Push best-effort posterior a la operación/alerta; no exponer nunca `VAPID_PRIVATE_KEY`, ni convertir fallos del servicio push en fallos de negocio.
- No llamar `sendNotification()` en WebPush v11: usar `queueNotification()` antes de `flush()`.

# Errores a evitar
- No omitir migraciones SQL ni actualizaciones Swagger cuando cambia el contrato.
- No confiar en MIME/nombres de archivos informados por el cliente; inspeccionar y procesar el contenido.
- No escribir contraseñas en claro en operaciones CRUD dinámicas.
- No hacer consultas N+1 para evidencias ni exponer audit trail desde portal público.
- No guardar `updated_by` de alertas sin validarlo contra el usuario autenticado; el endpoint de lectura masiva aún ignora ese campo.
- Al introducir `backend_uploads` por primera vez, respaldar/copiar las evidencias del contenedor antiguo antes de recrearlo; un volumen nuevo no recupera los archivos de la capa escribible del contenedor anterior.
