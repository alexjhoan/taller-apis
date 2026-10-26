<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class OrderSummaryMailer
{
  private const STATUS_LABELS = [
    'received' => 'Recibido',
    'diagnosing' => 'En diagnóstico',
    'awaiting_approval' => 'Esperando aprobación',
    'approved' => 'Aprobado',
    'in_progress' => 'En taller',
    'ready' => 'Listo para entregar',
    'delivered' => 'Entregado',
    'cancelled' => 'Cancelado',
  ];

  private const STATUS_COLORS = [
    'received' => '#3b82f6',
    'diagnosing' => '#f59e0b',
    'awaiting_approval' => '#f59e0b',
    'approved' => '#22c55e',
    'in_progress' => '#3b82f6',
    'ready' => '#22c55e',
    'delivered' => '#64748b',
    'cancelled' => '#ef4444',
  ];

  public function send(array $order, array $budget, int $completed, int $total, string $prompt): void
  {
    $mailer = new PHPMailer(true);
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $mailer->isMail();
    $mailer->setFrom(...$this->sender());
    $mailer->addAddress((string) $order['customer_email'], (string) $order['customer_name']);
    $mailer->isHTML(true);
    $mailer->Subject = sprintf('Mecaflow - Resumen de tu Orden #%d', (int) $order['id']);
    $mailer->Body = $this->html($order, $budget, $completed, $total, $prompt);
    $mailer->AltBody = $this->plainText($order, $budget, $completed, $total, $prompt);
    $mailer->send();
  }

  private function sender(): array
  {
    $configured = defined('MAIL_DEFAULT_FROM')
      ? (string) MAIL_DEFAULT_FROM
      : (defined('EMAIL_FROM') ? (string) EMAIL_FROM : 'no-reply@example.com');

    if (preg_match('/^\s*(.*?)\s*<([^<>]+)>\s*$/', $configured, $matches) === 1) {
      return [trim($matches[2]), trim($matches[1], " \t\n\r\0\x0B\"")];
    }

    return [$configured, 'Mecaflow'];
  }

  private function html(array $order, array $budget, int $completed, int $total, string $prompt): string
  {
    $status = (string) ($order['status'] ?? '');
    $statusLabel = self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status ?: 'Sin estado'));
    $statusColor = self::STATUS_COLORS[$status] ?? '#64748b';
    $percentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
    $vehicleYear = $order['vehicle_year'] !== null ? $this->escape((string) $order['vehicle_year']) . ' ' : '';
    $createdAt = date('d/m/Y', strtotime((string) $order['created_at']));
    $portalUrl = $this->portalUrl((string) $order['public_token']);

    $budgetHtml = '';
    if ($budget) {
      $rows = '';
      $sum = 0.0;
      foreach ($budget as $item) {
        $quantity = (float) $item['quantity'];
        $unitPrice = (float) $item['unit_price'];
        $lineTotal = $quantity * $unitPrice;
        $sum += $lineTotal;
        $rows .= '<tr>'
          . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;">' . $this->escape((string) $item['description']) . '</td>'
          . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:center;">' . $this->escape($this->quantity($quantity)) . '</td>'
          . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $this->money($unitPrice) . '</td>'
          . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:right;">' . $this->money($lineTotal) . '</td>'
          . '<td style="padding:10px 12px;border-bottom:1px solid #e2e8f0;">' . $this->escape($this->decisionLabel((string) ($item['status'] ?? ''))) . '</td>'
          . '</tr>';
      }

      $budgetHtml = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">'
        . '<thead><tr style="background:#1e293b;color:#ffffff;"><th style="padding:10px 12px;text-align:left;">Concepto</th><th style="padding:10px 12px;text-align:center;">Cant.</th><th style="padding:10px 12px;text-align:right;">Precio</th><th style="padding:10px 12px;text-align:right;">Total</th><th style="padding:10px 12px;text-align:left;">Estado</th></tr></thead>'
        . '<tbody>' . $rows
        . '<tr style="font-weight:bold;background:#f0fdf4;"><td colspan="3" style="padding:12px;text-align:right;">TOTAL</td><td style="padding:12px;text-align:right;">' . $this->money($sum) . '</td><td></td></tr>'
        . '</tbody></table>';
    }

    $additionalMessage = $prompt !== ''
      ? '<div style="background:#f8fafc;border-left:4px solid #3b82f6;padding:16px;margin:16px 24px;color:#334155;"><strong>Mensaje adicional</strong><br>' . nl2br($this->escape($prompt)) . '</div>'
      : '';

    return '<!doctype html><html lang="es"><body style="margin:0;padding:0;background:#f1f5f9;font-family:\'Segoe UI\',Arial,sans-serif;color:#1e293b;">'
      . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f1f5f9;"><tr><td align="center" style="padding:16px 8px;">'
      . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;">'
      . '<tr><td style="background:#1e293b;padding:24px;text-align:center;"><h1 style="color:#ffffff;margin:0;font-size:24px;">Mecaflow</h1><p style="color:#94a3b8;margin:4px 0 0;font-size:14px;">Resumen de Orden #' . (int) $order['id'] . '</p></td></tr>'
      . '<tr><td style="padding:24px 24px 8px;"><p style="margin:0 0 12px;">Hola ' . $this->escape((string) $order['customer_name']) . ',</p><p style="margin:0;color:#475569;">Te compartimos el resumen actualizado de tu orden de trabajo.</p></td></tr>'
      . '<tr><td><div style="background:#f8fafc;border-left:4px solid #3b82f6;padding:16px;margin:16px 24px;border-radius:8px;">'
      . '<div style="font-size:12px;text-transform:uppercase;color:#64748b;letter-spacing:.5px;margin-bottom:8px;">Datos del vehículo</div>'
      . '<p style="margin:6px 0;">' . $vehicleYear . $this->escape((string) $order['vehicle_make']) . ' ' . $this->escape((string) $order['vehicle_model']) . '</p>'
      . '<p style="margin:6px 0;">Placa: ' . $this->escape((string) $order['vehicle_plate']) . '</p>'
      . '<p style="margin:6px 0;">Fecha: ' . $this->escape($createdAt) . '</p>'
      . '<p style="margin:6px 0;">Estado: <span style="color:' . $statusColor . ';font-weight:bold;">' . $this->escape($statusLabel) . '</span></p>'
      . '</div></td></tr>'
      . '<tr><td><div style="background:#f8fafc;border-left:4px solid #22c55e;padding:16px;margin:16px 24px;border-radius:8px;">'
      . '<div style="font-size:12px;text-transform:uppercase;color:#64748b;letter-spacing:.5px;margin-bottom:8px;">Progreso del checklist</div>'
      . '<p style="margin:0 0 10px;">Checklist: ' . $completed . '/' . $total . ' (' . $percentage . '%)</p>'
      . '<div style="background:#e2e8f0;border-radius:999px;height:12px;overflow:hidden;"><div style="background:#22c55e;height:12px;width:' . $percentage . '%;"></div></div>'
      . '</div></td></tr>'
      . ($budgetHtml !== '' ? '<tr><td style="padding:8px 24px 0;"><h2 style="font-size:16px;margin:8px 0 12px;">Presupuesto</h2>' . $budgetHtml . '</td></tr>' : '')
      . ($additionalMessage !== '' ? '<tr><td>' . $additionalMessage . '</td></tr>' : '')
      . '<tr><td style="padding:8px 0 0;"><a href="' . $this->escape($portalUrl) . '" style="display:block;background:#22c55e;color:#ffffff;text-align:center;padding:16px 24px;border-radius:8px;font-weight:bold;font-size:16px;text-decoration:none;margin:24px;">VER MI ORDEN EN LÍNEA &rarr;</a>'
      . '<p style="margin:0 24px 24px;text-align:center;font-size:12px;color:#64748b;word-break:break-all;">' . $this->escape($portalUrl) . '</p></td></tr>'
      . '<tr><td style="background:#1e293b;color:#94a3b8;padding:20px 24px;text-align:center;font-size:12px;">Este es un correo automático de Mecaflow. No respondas a este mensaje.<br>&copy; ' . date('Y') . ' Mecaflow Workshop OS</td></tr>'
      . '</table></td></tr></table></body></html>';
  }

  private function plainText(array $order, array $budget, int $completed, int $total, string $prompt): string
  {
    $status = (string) ($order['status'] ?? '');
    $statusLabel = self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status ?: 'Sin estado'));
    $percentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
    $lines = [
      'Hola ' . (string) $order['customer_name'] . ',',
      '',
      'Resumen de Orden #' . (int) $order['id'],
      'Vehículo: ' . trim((string) ($order['vehicle_year'] ?? '') . ' ' . $order['vehicle_make'] . ' ' . $order['vehicle_model']),
      'Placa: ' . (string) $order['vehicle_plate'],
      'Fecha: ' . date('d/m/Y', strtotime((string) $order['created_at'])),
      'Estado: ' . $statusLabel,
      'Checklist: ' . $completed . '/' . $total . ' (' . $percentage . '%)',
    ];

    if ($budget) {
      $lines[] = '';
      $lines[] = 'Presupuesto:';
      $sum = 0.0;
      foreach ($budget as $item) {
        $lineTotal = (float) $item['quantity'] * (float) $item['unit_price'];
        $sum += $lineTotal;
        $lines[] = '- ' . $item['description'] . ' x ' . $this->quantity((float) $item['quantity']) . ': ' . $this->money($lineTotal) . ' (' . $this->decisionLabel((string) ($item['status'] ?? '')) . ')';
      }
      $lines[] = 'Total: ' . $this->money($sum);
    }

    if ($prompt !== '') {
      $lines[] = '';
      $lines[] = 'Mensaje adicional: ' . $prompt;
    }

    $lines[] = '';
    $lines[] = 'Consulta tu orden: ' . $this->portalUrl((string) $order['public_token']);
    return implode("\n", $lines);
  }

  private function portalUrl(string $token): string
  {
    if (!defined('PUBLIC_PORTAL_URL') || trim((string) PUBLIC_PORTAL_URL) === '') {
      throw new RuntimeException('PUBLIC_PORTAL_URL is not configured');
    }

    $baseUrl = rtrim((string) PUBLIC_PORTAL_URL, '?&');
    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    return $baseUrl . $separator . http_build_query(['token' => $token]);
  }

  private function money(float $amount): string
  {
    return '$' . number_format($amount, 2, ',', '.');
  }

  private function quantity(float $quantity): string
  {
    return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',');
  }

  private function decisionLabel(string $decision): string
  {
    return [
      'pending' => 'Pendiente',
      'accepted' => 'Aceptado',
      'rejected' => 'Rechazado',
    ][$decision] ?? 'Sin estado';
  }

  private function escape(string $value): string
  {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
