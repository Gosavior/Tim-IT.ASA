<?php
require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/avatar.php';

$birthdaysToday = [];
try {
  $birthdayStmt = $pdo->prepare("
    SELECT id, username, full_name, birth_date
    FROM users
    WHERE is_active = 1
      AND birth_date IS NOT NULL
      AND DATE_FORMAT(birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
    ORDER BY full_name ASC
  ");
  $birthdayStmt->execute();
  $birthdaysToday = $birthdayStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $birthdayError) {
  $birthdaysToday = [];
}

$todayBirthdayLabel = date('d F Y');
if (class_exists('IntlDateFormatter')) {
  try {
    $formatter = new IntlDateFormatter('id_ID', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'Asia/Jakarta');
    $todayBirthdayLabel = $formatter->format(new DateTime('now', new DateTimeZone('Asia/Jakarta')));
  } catch (Throwable $formatterError) {
    $todayBirthdayLabel = date('d F Y');
  }
}

$avatar_url = getAvatarUrl($user);

$roleStr = strtolower(trim($_SESSION['role'] ?? ''));
$isAdmin = $roleStr && preg_match('/admin|administrator/', $roleStr);
if ($isAdmin) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM tool_permits WHERE status = 'pending' AND created_at <= DATE_ADD(NOW(), INTERVAL 7 HOUR)");
  $stmt->execute();
  $pending_loans = (int) $stmt->fetchColumn();
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'");
  $stmt->execute();
  $pending_leaves = (int) $stmt->fetchColumn();

  $pending_password_resets = 0;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'");
    $stmt->execute();
    $pending_password_resets = (int) $stmt->fetchColumn();
  } catch (Exception $e) {
    $pending_password_resets = 0;
  }

  $pending_material_requests = 0;
  $edited_material_requests = 0;
  try {
    if ($isAdmin) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM material_requests WHERE status = 'sales_approved'");
      $stmt->execute();
      $pending_material_requests = (int) $stmt->fetchColumn();
    } else {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM material_requests WHERE user_id = ? AND sales_edited_at IS NOT NULL AND sales_edit_read = 0");
      $stmt->execute([$_SESSION['user_id']]);
      $edited_material_requests = (int) $stmt->fetchColumn();
    }
  } catch (Exception $e) {
    $pending_material_requests = 0;
    $edited_material_requests = 0;
  }

  $birthday_count = 0;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1 AND birth_date IS NOT NULL AND DATE_FORMAT(birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')");
    $stmt->execute();
    $birthday_count = (int) $stmt->fetchColumn();
  } catch (Exception $e) {
    $birthday_count = 0;
  }

  $pending_notifications = $pending_loans + $pending_leaves + $pending_password_resets + $pending_material_requests + $edited_material_requests;


  $pending_reimbursements = 0;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fuel_reimbursements WHERE status = 'pending'");
    $stmt->execute();
    $pending_reimbursements = (int) $stmt->fetchColumn();
    $pending_notifications += $pending_reimbursements;
  } catch (Exception $e) {
  }

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE status IN ('approved', 'rejected') AND decided_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
  $stmt->execute();
  $recent_processed = (int) $stmt->fetchColumn();
} else {
  $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tool_permits 
        WHERE status = 'pending' AND created_at <= DATE_ADD(NOW(), INTERVAL 7 HOUR)
        AND (
            (to_user_id = ? AND permit_type IN ('handover', 'return'))
            OR (from_user_id = ? AND permit_type = 'handover')
        )
    ");
  $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
  $pending_loans = (int) $stmt->fetchColumn();


  $edited_material_requests = 0;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM material_requests WHERE user_id = ? AND sales_edited_at IS NOT NULL AND sales_edit_read = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $edited_material_requests = (int) $stmt->fetchColumn();
  } catch (Exception $e) {
    $edited_material_requests = 0;
  }

  $pending_notifications = $pending_loans + $edited_material_requests;
  $recent_processed = 0;
}

$overdue_warning_count = 0;
if ($isAdmin) {
  $overdueQuery = "
    SELECT COUNT(*) AS overdue_total
    FROM tool_permits tp
    JOIN tools t ON t.id = tp.tool_id
    JOIN users uto ON uto.id = tp.to_user_id
    WHERE tp.status = 'approved'
      AND tp.permit_type IN ('loan', 'handover', 'project')
      AND t.tool_type = 'company'
      AND t.current_status IN ('Loan', 'Handover', 'Project')
      AND uto.role = 'technician'
      AND uto.is_active = 1
      AND TIMESTAMPDIFF(HOUR, COALESCE(tp.start_date, tp.approved_at, tp.created_at), NOW()) > 72
      AND NOT EXISTS (
        SELECT 1 FROM tool_permits ret
        WHERE ret.tool_id = tp.tool_id AND ret.status = 'approved'
        AND ret.permit_type IN ('return', 'force_return') AND ret.id > tp.id
      )
      AND tp.id = (
        SELECT tp2.id
        FROM tool_permits tp2
        WHERE tp2.tool_id = tp.tool_id
        AND tp2.status = 'approved'
        AND tp2.permit_type IN ('loan', 'handover', 'project')
        ORDER BY COALESCE(tp2.approved_at, tp2.created_at) DESC, tp2.id DESC
        LIMIT 1
      )
  ";

  try {
    $stmt = $pdo->prepare($overdueQuery);
    $stmt->execute();
    $overdue_warning_count = (int) ($stmt->fetchColumn() ?: 0);
  } catch (Throwable $overdueEx) {
    $overdue_warning_count = 0;
  }
} else {
  $overdueQuery = "
    SELECT COUNT(*) AS overdue_total
    FROM tool_permits tp
    JOIN tools t ON t.id = tp.tool_id
    JOIN users uto ON uto.id = tp.to_user_id
    WHERE tp.status = 'approved'
      AND tp.permit_type IN ('loan', 'handover', 'project')
      AND t.tool_type = 'company'
      AND t.current_status IN ('Loan', 'Handover', 'Project')
      AND tp.to_user_id = ?
      AND uto.role = 'technician'
      AND uto.is_active = 1
      AND TIMESTAMPDIFF(HOUR, COALESCE(tp.start_date, tp.approved_at, tp.created_at), NOW()) > 72
      AND NOT EXISTS (
        SELECT 1 FROM tool_permits ret
        WHERE ret.tool_id = tp.tool_id AND ret.status = 'approved'
        AND ret.permit_type IN ('return', 'force_return') AND ret.id > tp.id
      )
      AND tp.id = (
        SELECT tp2.id
        FROM tool_permits tp2
        WHERE tp2.tool_id = tp.tool_id
        AND tp2.status = 'approved'
        AND tp2.permit_type IN ('loan', 'handover', 'project')
        ORDER BY COALESCE(tp2.approved_at, tp2.created_at) DESC, tp2.id DESC
        LIMIT 1
      )
  ";

  try {
    $stmt = $pdo->prepare($overdueQuery);
    $stmt->execute([$_SESSION['user_id']]);
    $overdue_warning_count = (int) ($stmt->fetchColumn() ?: 0);
  } catch (Throwable $overdueEx) {
    $overdue_warning_count = 0;
  }
}

$header_notification_total = ($pending_notifications ?? 0) + $overdue_warning_count + (($birthday_count ?? 0) > 0 ? 1 : 0);
$has_overdue_warning = $overdue_warning_count > 0;
$serverTheme = (($user['theme'] ?? ($_SESSION['theme'] ?? 'light')) === 'dark') ? 'dark' : 'light';
?>



<script>
  (function () {
    var theme = <?= json_encode($serverTheme) ?>;
    var html = document.documentElement;
    html.setAttribute('data-theme', theme);
    if (theme === 'dark') {
      html.classList.add('dark');
    } else {
      html.classList.remove('dark');
    }
  })();
</script>
<style>
  select option {
    background-color: #ffffff !important;
    color: #0f172a !important;
  }

  .dark select option {
    background-color: rgb(30 41 59) !important;
    color: #ffffff !important;
  }

  .notification-count {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: #ef4444;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
  }

  html[data-theme="light"] body {
    background-color: #f8fafc !important;
    color: #0f172a !important;
  }

  html[data-theme="dark"] body {
    background-color: #0f172a !important;
    color: #e2e8f0 !important;
  }

  html[data-theme="light"] input,
  html[data-theme="light"] select,
  html[data-theme="light"] textarea,
  html[data-theme="light"] .input-like {
    background-color: #ffffff !important;
    color: #0f172a !important;
    border-color: #cbd5f5 !important;
  }

  html[data-theme="dark"] input,
  html[data-theme="dark"] select,
  html[data-theme="dark"] textarea,
  html[data-theme="dark"] .input-like {
    background-color: #1e293b !important;
    color: #f8fafc !important;
    border-color: #334155 !important;
  }

  html[data-theme="light"] input::placeholder,
  html[data-theme="light"] textarea::placeholder {
    color: #94a3b8;
  }

  html[data-theme="dark"] input::placeholder,
  html[data-theme="dark"] textarea::placeholder {
    color: #cbd5f5;
  }

  html[data-theme="dark"] select option {
    background-color: #1e293b !important;
    color: #f8fafc !important;
  }

  html[data-theme="dark"] .bg-white,
  html[data-theme="dark"] .bg-slate-50,
  html[data-theme="dark"] .bg-slate-100,
  html[data-theme="dark"] .bg-blue-50,
  html[data-theme="dark"] .bg-slate-200,
  html[data-theme="dark"] .bg-gray-50,
  html[data-theme="dark"] .bg-gray-100,
  html[data-theme="dark"] .bg-gray-200 {
    background-color: #111827 !important;
    color: #e2e8f0 !important;
  }

  html[data-theme="dark"] .border-slate-200,
  html[data-theme="dark"] .border-gray-200,
  html[data-theme="dark"] .border-slate-300,
  html[data-theme="dark"] .border-gray-300 {
    border-color: #334155 !important;
  }

  html[data-theme="dark"] .text-slate-800,
  html[data-theme="dark"] .text-slate-700,
  html[data-theme="dark"] .text-slate-600,
  html[data-theme="dark"] .text-gray-800,
  html[data-theme="dark"] .text-gray-700,
  html[data-theme="dark"] .text-gray-600,
  html[data-theme="dark"] .text-gray-500,
  html[data-theme="dark"] .text-black {
    color: #e2e8f0 !important;
  }

  html[data-theme="dark"] .text-gray-400,
  html[data-theme="dark"] .text-slate-400 {
    color: #94a3b8 !important;
  }

  html[data-theme="dark"] .text-gray-900,
  html[data-theme="dark"] .text-slate-900 {
    color: #f1f5f9 !important;
  }

  html[data-theme="dark"] .border-dashed {
    border-color: #475569 !important;
  }

  html[data-theme="dark"] .hover\:bg-gray-50:hover,
  html[data-theme="dark"] .hover\:bg-slate-50:hover {
    background-color: #1e293b !important;
  }

  html[data-theme="dark"] .bg-black\/50,
  html[data-theme="dark"] .bg-black.bg-opacity-50 {
    background-color: rgba(0, 0, 0, 0.75) !important;
  }

  html[data-theme="dark"] .shadow-sm,
  html[data-theme="dark"] .shadow {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.4), 0 1px 2px rgba(0, 0, 0, 0.3) !important;
  }

  html[data-theme="dark"] .focus\:ring-primary-500:focus {
    --tw-ring-color: rgba(59, 130, 246, 0.5) !important;
  }

  html[data-theme="dark"] table,
  html[data-theme="dark"] thead,
  html[data-theme="dark"] tbody {
    background-color: transparent !important;
  }

  html[data-theme="dark"] th {
    background-color: #1e293b !important;
    color: #e2e8f0 !important;
    border-color: #334155 !important;
  }

  html[data-theme="dark"] td {
    color: #cbd5e1 !important;
    border-color: #1e293b !important;
  }

  html[data-theme="dark"] tr:hover td {
    background-color: rgba(30, 41, 59, 0.5) !important;
  }

  html[data-theme="dark"] .bg-blue-100,
  html[data-theme="dark"] .bg-green-100,
  html[data-theme="dark"] .bg-yellow-100,
  html[data-theme="dark"] .bg-red-100,
  html[data-theme="dark"] .bg-purple-100,
  html[data-theme="dark"] .bg-indigo-100,
  html[data-theme="dark"] .bg-pink-100,
  html[data-theme="dark"] .bg-orange-100 {
    opacity: 0.85;
  }

  html[data-theme="dark"] label,
  html[data-theme="dark"] legend {
    color: #cbd5e1 !important;
  }

  html[data-theme="dark"] h1,
  html[data-theme="dark"] h2,
  html[data-theme="dark"] h3,
  html[data-theme="dark"] h4,
  html[data-theme="dark"] h5,
  html[data-theme="dark"] h6 {
    color: #f1f5f9 !important;
  }

  html[data-theme="dark"] p {
    color: #cbd5e1 !important;
  }

  html[data-theme="dark"] .bg-gray-300 {
    background-color: #475569 !important;
    color: #e2e8f0 !important;
  }

  html[data-theme="light"] .dark\:bg-slate-900,
  html[data-theme="light"] .dark\:bg-slate-800,
  html[data-theme="light"] .dark\:bg-slate-700 {
    background-color: inherit !important;
  }

  html[data-theme="light"] .dark\:text-white,
  html[data-theme="light"] .dark\:text-slate-200,
  html[data-theme="light"] .dark\:text-slate-300 {
    color: inherit !important;
  }

  html[data-theme="light"],
  html[data-theme="light"] body {
    color-scheme: light !important;
  }

  html[data-theme="dark"],
  html[data-theme="dark"] body {
    color-scheme: dark !important;
  }

  .birthday-strip {
    position: relative;
    z-index: 1;
    margin: 2.5rem auto 0;
    width: min(96vw, 820px);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(148, 163, 184, 0.35);
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.18);
    backdrop-filter: blur(14px);
    padding: 0.9rem 1.2rem;
    color: #0f172a;
  }

  .dark .birthday-strip {
    background: rgba(15, 23, 42, 0.92);
    border-color: rgba(148, 163, 184, 0.25);
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.45);
    color: #e2e8f0;
  }

  .birthday-strip__title {
    font-weight: 600;
    font-size: 0.95rem;
    letter-spacing: -0.01em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .birthday-strip__date {
    font-size: 0.82rem;
    font-weight: 500;
    color: #64748b;
  }

  .dark .birthday-strip__date {
    color: #cbd5e1;
  }

  .birthday-strip__list {
    margin-top: 0.5rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
  }

  .birthday-strip__link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.08);
    color: #1d4ed8;
    font-size: 0.87rem;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
  }

  .birthday-strip__link:hover {
    background: rgba(37, 99, 235, 0.16);
    color: #1d4ed8;
  }

  .dark .birthday-strip__link {
    background: rgba(96, 165, 250, 0.14);
    color: #bfdbfe;
  }

  .dark .birthday-strip__link:hover {
    background: rgba(96, 165, 250, 0.24);
    color: #e0f2fe;
  }

  .birthday-strip__username {
    font-size: 0.78rem;
    color: #64748b;
  }

  .dark .birthday-strip__username {
    color: #cbd5f5;
  }

  .birthday-strip__empty {
    margin-top: 0.45rem;
    font-size: 0.86rem;
    color: #475569;
  }

  .dark .birthday-strip__empty {
    color: #94a3b8;
  }

  .birthday-strip__close {
    position: absolute;
    top: 0.35rem;
    right: 0.4rem;
    border: none;
    background: transparent;
    color: inherit;
    cursor: pointer;
    font-size: 1rem;
    padding: 0.25rem;
    border-radius: 999px;
    opacity: 0.7;
    transition: opacity 0.15s ease;
  }

  .birthday-strip__close:hover {
    opacity: 1;
  }

  html[data-theme="light"] [data-theme-container] {
    background: #ffffff !important;
    color: #0f172a !important;
  }

  html[data-theme="dark"] [data-theme-container] {
    background: linear-gradient(145deg, #0f172a, #1e293b) !important;
    color: #e2e8f0 !important;
  }

  .notification-count.alert {
    background: #f97316;
    box-shadow: 0 0 0 rgba(249, 115, 22, 0.45);
    animation: badgePulse 1.5s ease-out infinite;
  }

  animation: bellSwing 1.6s ease-in-out infinite;
  transform-origin: top center;
  }

  .notification-warning-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    background: rgba(249, 115, 22, 0.18);
    color: #ea580c;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    font-weight: 700;
    animation: badgePulse 1.6s ease-out infinite;
  }

  .birthday-thread-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.55);
    z-index: 99998;
    padding: 16px;
  }

  .birthday-thread-card {
    width: min(720px, 95vw);
    max-height: 90vh;
    background: #fff;
    color: #0f172a;
    border-radius: 18px;
    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.35);
    display: flex;
    flex-direction: column;
    position: relative;
    padding: 28px 24px 20px 24px;
  }

  .dark .birthday-thread-card {
    background: #0f172a;
    color: #e2e8f0;
    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.6);
  }

  .birthday-thread-close {
    position: absolute;
    top: 14px;
    right: 16px;
    border: none;
    background: transparent;
    color: inherit;
    font-size: 1.6rem;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s ease;
  }

  .birthday-thread-close:hover {
    opacity: 1;
  }

  .birthday-thread-title {
    font-size: 1.35rem;
    font-weight: 600;
    margin-bottom: 18px;
  }

  .birthday-thread-users {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 16px;
  }

  .birthday-thread-user {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
    border-radius: 999px;
    background: #e0f2fe;
    color: #1d4ed8;
    padding: 6px 12px;
    font-size: 0.9rem;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
  }

  .birthday-thread-user:hover {
    background: #bfdbfe;
    transform: translateY(-1px);
  }

  .birthday-thread-user-active {
    background: #2563eb;
    color: #fff;
  }

  .dark .birthday-thread-user {
    background: rgba(59, 130, 246, 0.18);
    color: #bfdbfe;
  }

  .dark .birthday-thread-user:hover {
    background: rgba(96, 165, 250, 0.28);
  }

  .dark .birthday-thread-user-active {
    background: #3b82f6;
    color: #fff;
  }

  .birthday-thread-messages {
    flex: 1 1 auto;
    overflow-y: auto;
    padding-right: 4px;
    margin-bottom: 18px;
  }

  .birthday-thread-empty {
    font-size: 0.92rem;
    color: #64748b;
    text-align: center;
    margin-bottom: 16px;
  }

  .dark .birthday-thread-empty {
    color: #cbd5e1;
  }

  .birthday-thread-message {
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    padding: 12px 14px;
    margin-bottom: 12px;
  }

  .dark .birthday-thread-message {
    border-color: #1e293b;
    background: rgba(15, 23, 42, 0.9);
  }

  .birthday-thread-replies {
    margin-left: 28px;
    padding-left: 12px;
    border-left: 1px solid rgba(37, 99, 235, 0.2);
  }

  .dark .birthday-thread-replies {
    border-left-color: rgba(148, 163, 184, 0.35);
  }

  .birthday-thread-message-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
  }

  .birthday-thread-message-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #bfdbfe;
  }

  .dark .birthday-thread-message-avatar {
    border-color: rgba(147, 197, 253, 0.6);
  }

  .birthday-thread-message-meta {
    display: flex;
    flex-direction: column;
    font-size: 0.85rem;
    color: #475569;
  }

  .dark .birthday-thread-message-meta {
    color: #94a3b8;
  }

  .birthday-thread-message-body {
    font-size: 0.95rem;
    color: #1f2937;
    margin-bottom: 8px;
    white-space: pre-wrap;
  }

  .dark .birthday-thread-message-body {
    color: #e2e8f0;
  }

  .birthday-thread-message-actions {
    display: flex;
    gap: 12px;
    font-size: 0.82rem;
  }

  .birthday-thread-reply-btn {
    border: none;
    background: transparent;
    color: #2563eb;
    cursor: pointer;
    padding: 0;
  }

  .birthday-thread-reply-btn:hover {
    text-decoration: underline;
  }

  .dark .birthday-thread-reply-btn {
    color: #93c5fd;
  }

  .birthday-thread-form textarea {
    width: 100%;
    border: 1.5px solid #cbd5f5;
    border-radius: 10px;
    padding: 10px 12px;
    resize: vertical;
    font-size: 1rem;
    background: #fff;
    color: #0f172a;
  }

  .dark .birthday-thread-form textarea {
    background: #0f172a;
    color: #e2e8f0;
    border-color: #1e293b;
  }

  .birthday-thread-form-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
  }

  .birthday-thread-submit {
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 8px 18px;
    font-weight: 600;
    cursor: pointer;
  }

  .birthday-thread-submit:hover {
    background: #1d4ed8;
  }

  .birthday-thread-cancel-reply {
    background: transparent;
    border: none;
    color: #64748b;
    cursor: pointer;
  }

  .dark .birthday-thread-cancel-reply {
    color: #cbd5e1;
  }

  .birthday-thread-reply-info {
    font-size: 0.85rem;
    color: #475569;
    margin-bottom: 8px;
  }

  .dark .birthday-thread-reply-info {
    color: #cbd5e1;
  }

  .birthday-thread-loading {
    text-align: center;
    padding: 20px 0;
    color: #64748b;
    font-size: 0.95rem;
  }

  .dark .birthday-thread-loading {
    color: #cbd5e1;
  }

  .birthday-thread-error {
    color: #dc2626;
    text-align: center;
    font-size: 0.9rem;
    margin-bottom: 12px;
  }

  @media (max-width: 520px) {
    .birthday-thread-card {
      padding: 24px 18px 16px 18px;
    }

    .birthday-thread-message {
      padding: 10px 12px;
    }
  }

  @keyframes badgePulse {
    0% {
      transform: scale(1);
      box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.35);
    }

    55% {
      transform: scale(1.12);
      box-shadow: 0 0 0 12px rgba(249, 115, 22, 0);
    }

    100% {
      transform: scale(1);
      box-shadow: 0 0 0 0 rgba(249, 115, 22, 0);
    }
  }

  @keyframes bellSwing {

    0%,
    100% {
      transform: rotate(0deg);
    }

    20% {
      transform: rotate(10deg);
    }

    40% {
      transform: rotate(-8deg);
    }

    60% {
      transform: rotate(6deg);
    }

    80% {
      transform: rotate(-4deg);
    }
  }

  header {
    background: linear-gradient(135deg, #ffffff 0%, #f1f5f9 45%, #ffffff 100%) !important;
    border-bottom: 1px solid rgba(226, 232, 240, 0.6);
    box-shadow: 0 2px 8px -4px rgba(15, 23, 42, 0.12);
  }

  @media (max-width: 767px),
  (max-height: 500px) {
    header {
      left: 0 !important;
      width: 100% !important;
    }

    #sidebarToggle {
      display: block !important;
    }
  }

  @media (min-width: 768px) and (min-height: 501px) {
    header {
      left: 16rem !important;
      width: calc(100% - 16rem) !important;
    }

    #sidebarToggle {
      display: none !important;
    }
  }

  html[data-theme="dark"] header,
  .dark header {
    background: linear-gradient(140deg, #111827 0%, #1e293b 55%, #111827 100%) !important;
    border-bottom: 1px solid rgba(51, 65, 85, 0.5);
    box-shadow: 0 2px 8px -4px rgba(0, 0, 0, 0.4);
  }
</style>

<script>
  (function () {
    try {
      var serverTheme = <?= json_encode($serverTheme) ?>;
      document.documentElement.setAttribute('data-theme', serverTheme);
      if (window.ThemeManager) {
        window.ThemeManager.syncWithServer(serverTheme);
      }
    } catch (e) {
      console.error('Theme sync error:', e);
    }
  })();

  window.BASE_URL = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '') + '/';
  const APP_BASE_SEGMENT = window.BASE_URL;

  function prependBasePath(relPath) {
    const normalizedPath = String(relPath || '').replace(/^\/+/, '');
    if (!normalizedPath) {
      return APP_BASE_SEGMENT ? `/${APP_BASE_SEGMENT}/` : '/';
    }
    if (!APP_BASE_SEGMENT) {
      return `/${normalizedPath}`;
    }
    const baseNormalized = APP_BASE_SEGMENT.replace(/^\/+/, '');
    if (normalizedPath.startsWith(baseNormalized + '/')) {
      return `/${normalizedPath}`;
    }
    return `/${baseNormalized}/${normalizedPath}`;
  }

  function assetUrl(p) {
    const baseUrl = (typeof BASE_URL !== 'undefined' && BASE_URL) ? BASE_URL.replace(/\/+$/, '') : '';
    const fallback = baseUrl ? baseUrl + '/public/assets/images/avatar-default.png' : '../../public/assets/images/avatar-default.png';
    if (!p) return fallback;
    try {
      let str = String(p).trim();
      if (!str || str === 'null' || str === 'undefined') return fallback;
      if (/\$\{[^}]+\}/.test(str)) return fallback;
      if (/^https?:\/\//i.test(str)) return str;
      str = str.replace(/^\.\//, '').replace(/^\/+/, '');
      if (!str) return fallback;
      if (str.startsWith('storage/')) {
        if (baseUrl) return baseUrl + '/serve_image.php?path=' + encodeURIComponent(str);
        return '/serve_image.php?path=' + encodeURIComponent(str);
      }

      if (baseUrl) {
        return baseUrl + '/' + str;
      }

      if (str.startsWith('public/')) {
        return '../../' + str;
      }
      return '../../' + str;
    } catch (e) {
      return fallback;
    }
  }

  function resolveProofAsset(rawPath) {
    if (!rawPath) return null;
    let path = String(rawPath).trim();
    if (path.startsWith('[')) {
      try {
        const arr = JSON.parse(path);
        if (Array.isArray(arr) && arr.length > 0) {
          path = String(arr[0]).trim();
        } else {
          return null;
        }
      } catch (e) {
        return null;
      }
    }
    if (!path || path === 'null' || path === 'undefined') return null;
    const resolved = assetUrl(path);
    if (!resolved || /\$\{[^}]+\}/.test(resolved)) {
      return null;
    }
    return resolved;
  }

  function attachAvatarFallback(imgEl) {
    if (!imgEl) {
      return;
    }
    const fallbackSrc = assetUrl('public/assets/images/avatar-default.png');
    imgEl.addEventListener('error', function handleAvatarError() {
      if (imgEl.dataset.fallbackApplied === '1') {
        return;
      }
      imgEl.dataset.fallbackApplied = '1';
      imgEl.src = fallbackSrc;
    }, { once: true });
  }

  function htmlEscape(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  (function () {
    if (!document.getElementById('global-toast-css')) {
      var s = document.createElement('style');
      s.id = 'global-toast-css';
      s.textContent =
        '@keyframes gtoast-in{0%{transform:scale(.3);opacity:0}50%{transform:scale(1.05)}70%{transform:scale(.95)}100%{transform:scale(1);opacity:1}}' +
        '@keyframes gtoast-out{from{opacity:1;transform:scale(1)}to{opacity:0;transform:scale(.8)}}' +
        '@keyframes gtoast-circle{from{stroke-dashoffset:166}to{stroke-dashoffset:0}}' +
        '@keyframes gtoast-check{from{stroke-dashoffset:48}to{stroke-dashoffset:0}}' +
        '@keyframes gtoast-x{from{stroke-dashoffset:20}to{stroke-dashoffset:0}}' +
        '@keyframes gtoast-fill{from{opacity:0;transform:scale(0)}to{opacity:.15;transform:scale(1)}}' +
        '.gtoast-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.3);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}' +
        '.gtoast-card{background:#fff;border-radius:1.25rem;padding:2rem 1.5rem 1.5rem;text-align:center;min-width:260px;max-width:320px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);animation:gtoast-in .5s cubic-bezier(.175,.885,.32,1.275)}' +
        '.gtoast-card.closing{animation:gtoast-out .3s ease forwards}' +
        '.gtoast-icon{width:80px;height:80px;margin:0 auto 1rem;position:relative}' +
        '.gtoast-circ{fill:none;stroke-width:3;stroke-linecap:round;stroke-dasharray:166;stroke-dashoffset:166;animation:gtoast-circle .6s .1s cubic-bezier(.65,0,.45,1) forwards}' +
        '.gtoast-chk{fill:none;stroke:#fff;stroke-width:3.5;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:48;stroke-dashoffset:48;animation:gtoast-check .35s .5s cubic-bezier(.65,0,.45,1) forwards}' +
        '.gtoast-xl{fill:none;stroke:#fff;stroke-width:3.5;stroke-linecap:round;stroke-dasharray:20;stroke-dashoffset:20;animation:gtoast-x .3s .5s cubic-bezier(.65,0,.45,1) forwards}' +
        '.gtoast-bg{transform-origin:center;animation:gtoast-fill .4s .4s ease forwards;opacity:0}' +
        '.dark .gtoast-card{background:#1f2937;color:#e5e7eb}';
      document.head.appendChild(s);
    }

    function globalShowToast(message, type) {
      type = type || 'info';
      var isMain = (type === 'success' || type === 'error');
      if (!isMain) {
        var colors = { warning: '#eab308', info: '#3b82f6' };
        var icons = { warning: '<i class="fas fa-exclamation-triangle"></i>', info: '<i class="fas fa-info-circle"></i>' };
        var bar = document.createElement('div');
        bar.style.cssText = 'position:fixed;top:1rem;left:1rem;right:1rem;z-index:99999;display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border-radius:.75rem;color:#fff;font-size:.875rem;font-weight:500;box-shadow:0 10px 25px rgba(0,0,0,.15);transform:translateY(-120%);transition:transform .3s;background:' + (colors[type] || colors.info);
        bar.innerHTML = '<span style="font-size:1.2rem">' + (icons[type] || icons.info) + '</span><span style="flex:1">' + message + '</span>';
        document.body.appendChild(bar);
        requestAnimationFrame(function () { bar.style.transform = 'translateY(0)'; });
        setTimeout(function () { bar.style.transform = 'translateY(-120%)'; setTimeout(function () { bar.remove(); }, 300); }, 3000);
        return;
      }
      var overlay = document.createElement('div');
      overlay.className = 'gtoast-overlay';
      var c = type === 'success' ? '#22c55e' : '#ef4444';
      var svg = type === 'success'
        ? '<circle class="gtoast-bg" cx="26" cy="26" r="25" fill="' + c + '"/><circle class="gtoast-circ" cx="26" cy="26" r="25" stroke="' + c + '"/><path class="gtoast-chk" d="M14 27l8 8 16-16"/>'
        : '<circle class="gtoast-bg" cx="26" cy="26" r="25" fill="' + c + '"/><circle class="gtoast-circ" cx="26" cy="26" r="25" stroke="' + c + '"/><line class="gtoast-xl" x1="18" y1="18" x2="34" y2="34"/><line class="gtoast-xl" x1="34" y1="18" x2="18" y2="34" style="animation-delay:.6s"/>';
      overlay.innerHTML = '<div class="gtoast-card"><div class="gtoast-icon"><svg viewBox="0 0 52 52" width="80" height="80">' + svg + '</svg></div>' +
        '<p style="font-size:1rem;font-weight:700;color:' + (type === 'success' ? '#16a34a' : '#dc2626') + ';margin-bottom:.25rem">' + (type === 'success' ? 'Berhasil!' : 'Gagal!') + '</p>' +
        '<p style="font-size:.875rem;color:#6b7280">' + message + '</p></div>';
      document.body.appendChild(overlay);
      overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
      function close() {
        var card = overlay.querySelector('.gtoast-card');
        if (card) card.classList.add('closing');
        overlay.style.opacity = '0'; overlay.style.transition = 'opacity .3s';
        setTimeout(function () { overlay.remove(); }, 300);
      }
      setTimeout(close, 2500);
    }

    window.showToast = globalShowToast;
    window.showHeaderToast = globalShowToast; // backward compat
  })();
</script>

<header
  class="flex justify-between items-center bg-white dark:bg-slate-900 dark:text-white p-4 fixed left-0 md:left-64 top-0 w-full md:w-[calc(100%-16rem)] z-40"
  style="background-color: #ffffff; box-shadow: 0 2px 8px -4px rgba(15, 23, 42, 0.12);">
  <script>
    (function () {
      var h = document.currentScript.parentElement;
      var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      if (isDark) {
        h.style.backgroundColor = '#111827';
        h.style.boxShadow = '0 2px 8px -4px rgba(0, 0, 0, 0.4)';
      }
    })();
  </script>

  <button class="md:hidden text-slate-700 dark:text-white hover:bg-blue-500/30 rounded-lg px-2 py-1 transition"
    id="sidebarToggle" type="button" aria-label="Open Sidebar">
    <i class="fas fa-bars text-2xl"></i>
  </button>

  <div class="flex-1 mx-4" style="position:relative;" id="globalSearchWrap">
    <div style="position:relative;">
      <i class="fas fa-search"
        style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.85rem;pointer-events:none;"></i>
      <input type="text" placeholder="Search pages, staff, tools..." id="globalSearchInput" autocomplete="off"
        style="width:100%;padding:8px 12px 8px 36px;border:1px solid #d1d5db;border-radius:10px;font-size:16px;font-family:inherit;outline:none;transition:border 0.15s,box-shadow 0.15s;box-sizing:border-box;"
        class="bg-white text-slate-900">
    </div>

    <div id="globalSearchResults" style="display:none;position:absolute;top:calc(100% + 6px);left:0;right:0;
             border-radius:14px;box-shadow:0 12px 36px rgba(0,0,0,0.12);
             max-height:420px;overflow-y:auto;z-index:9999;" class="bg-white border border-slate-200">
    </div>
  </div>
  <style>
    html[data-theme="dark"] #globalSearchInput {
      background: #0f172a !important;
      border-color: #475569 !important;
      color: #f1f5f9 !important;
    }

    html[data-theme="dark"] #globalSearchResults {
      background: #1e293b !important;
      border-color: #334155 !important;
    }

    .gs-group-title {
      padding: 8px 14px 4px;
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      color: #94a3b8;
    }

    .gs-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 9px 14px;
      cursor: pointer;
      transition: background 0.12s;
      text-decoration: none;
      color: inherit;
    }

    .gs-item:hover,
    .gs-item.gs-active {
      background: rgba(59, 130, 246, 0.08);
    }

    .dark .gs-item:hover,
    .dark .gs-item.gs-active {
      background: rgba(59, 130, 246, 0.18);
    }

    .gs-icon {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      flex-shrink: 0;
    }

    .gs-icon-page {
      background: #dbeafe;
      color: #2563eb;
    }

    .dark .gs-icon-page {
      background: #1e3a8a;
      color: #93c5fd;
    }

    .gs-icon-staff {
      background: #d1fae5;
      color: #059669;
    }

    .dark .gs-icon-staff {
      background: #064e3b;
      color: #6ee7b7;
    }

    .gs-icon-tool {
      background: #fef3c7;
      color: #d97706;
    }

    .dark .gs-icon-tool {
      background: #78350f;
      color: #fde68a;
    }

    .gs-name {
      font-size: 0.82rem;
      font-weight: 600;
      color: #1e293b;
    }

    .dark .gs-name {
      color: #f1f5f9;
    }

    .gs-sub {
      font-size: 0.7rem;
      color: #94a3b8;
    }

    .gs-badge {
      padding: 2px 8px;
      border-radius: 20px;
      font-size: 0.62rem;
      font-weight: 600;
      margin-left: auto;
      flex-shrink: 0;
    }

    .gs-badge-ready {
      background: #d1fae5;
      color: #065f46;
    }

    .dark .gs-badge-ready {
      background: #064e3b;
      color: #6ee7b7;
    }

    .gs-badge-loan {
      background: #fee2e2;
      color: #991b1b;
    }

    .dark .gs-badge-loan {
      background: #7f1d1d;
      color: #fca5a5;
    }

    .gs-badge-handover {
      background: #e0e7ff;
      color: #3730a3;
    }

    .dark .gs-badge-handover {
      background: #312e81;
      color: #a5b4fc;
    }

    .gs-empty {
      padding: 24px 14px;
      text-align: center;
      font-size: 0.82rem;
      color: #94a3b8;
    }

    .gs-kbd {
      display: inline-block;
      padding: 1px 6px;
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      border-radius: 4px;
      font-size: 0.65rem;
      color: #64748b;
      font-family: monospace;
      margin-left: auto;
    }

    .dark .gs-kbd {
      background: #334155;
      border-color: #475569;
      color: #94a3b8;
    }

    @media (max-width:640px) {}
  </style>

  <div class="relative mx-4">
    <button
      class="relative text-slate-700 dark:text-white text-lg hover:text-blue-500 transition<?= $has_overdue_warning ? ' bell-alert' : '' ?>"
      id="notification-btn" data-has-overdue="<?= $has_overdue_warning ? '1' : '0' ?>">
      <i class="fas fa-bell"></i>
      <?php if ($header_notification_total > 0): ?>
        <span
          class="notification-count<?= $has_overdue_warning ? ' alert' : '' ?>"><?= (int) $header_notification_total ?></span>
      <?php endif; ?>
    </button>
    <div id="notification-dropdown"
      class="hidden fixed md:absolute left-4 right-4 md:left-auto md:right-6 md:translate-x-0 top-[60px] md:top-auto mt-2 w-auto md:w-80 bg-white dark:bg-slate-800/90 dark:text-white border border-slate-200 dark:border-white/10 rounded-lg shadow-lg z-50 max-h-[70vh] md:max-h-96 overflow-y-auto">
      <div class="p-3 border-b border-slate-200 dark:border-white/10 font-semibold">Notifikasi</div>
      <div id="notifications-list"></div>
      <div class="border-t border-slate-200 dark:border-white/10">
        <a href="?page=inbox<?= $isAdmin && $pending_password_resets > 0 ? '&section=password&status=pending' : '' ?>"
          class="block text-center text-blue-600 dark:text-blue-300 hover:underline p-3 text-sm">
          Lihat semua notifikasi
        </a>
      </div>
    </div>
  </div>

  <div class="relative">
    <button id="userMenuToggle" class="flex items-center gap-2 hover:bg-blue-500/30 px-2 py-1 rounded-lg transition">
      <span
        class="text-sm text-slate-700 dark:text-white font-medium"><?php echo htmlspecialchars($user['full_name']); ?></span>
      <div class="w-8 h-8 rounded-full overflow-hidden flex items-center justify-center">
        <img src="<?= $avatar_url ?>" alt="Avatar" class="w-8 h-8 rounded-full object-cover" id="headerAvatarImg">
      </div>
    </button>
    <script>
      (function () {
        attachAvatarFallback(document.getElementById('headerAvatarImg'));
      })();
    </script>
    <div id="userMenu"
      class="hidden absolute right-0 mt-2 w-48 bg-white dark:bg-slate-800/90 text-gray-900 dark:text-white border border-slate-200 dark:border-white/10 rounded-lg shadow-lg"
      style="z-index:9999;">
      <a href="?page=profile" class="block px-4 py-2 hover:bg-blue-500/30 text-sm">Profile</a>
      <a href="?page=settings" class="block px-4 py-2 hover:bg-blue-500/30 text-sm">Settings</a>
      <a href="./app/auth/logout.php" class="block px-4 py-2 hover:bg-blue-500/30 text-sm">Logout</a>
    </div>
  </div>
</header>

<div id="birthdayThreadModal" class="birthday-thread-modal" aria-hidden="true">
  <div class="birthday-thread-card" role="dialog" aria-modal="true" aria-labelledby="birthdayThreadTitle">
    <button type="button" id="birthdayThreadClose" class="birthday-thread-close" aria-label="Tutup">&times;</button>
    <h2 id="birthdayThreadTitle" class="birthday-thread-title">Ucapan Ulang Tahun</h2>
    <div id="birthdayThreadUsers" class="birthday-thread-users"></div>
    <div id="birthdayThreadError" class="birthday-thread-error" style="display:none;"></div>
    <div id="birthdayThreadMessages" class="birthday-thread-messages"></div>
    <div id="birthdayThreadEmpty" class="birthday-thread-empty" style="display:none;">Belum ada ucapan. Jadilah yang
      pertama!</div>
    <form id="birthdayThreadForm" class="birthday-thread-form" autocomplete="off">
      <input type="hidden" name="birthday_user_id" id="birthdayThreadUserId" value="">
      <input type="hidden" name="parent_id" id="birthdayThreadParentId" value="">
      <div id="birthdayThreadReplyInfo" class="birthday-thread-reply-info" style="display:none;"></div>
      <textarea id="birthdayThreadMessage" name="message" rows="3"
        placeholder="Tulis ucapan terbaikmu untuk rekan yang berulang tahun..."></textarea>
      <div class="birthday-thread-form-actions">
        <button type="button" id="birthdayThreadCancelReply" class="birthday-thread-cancel-reply"
          style="display:none;">Batal Balas</button>
        <button type="submit" class="birthday-thread-submit">Kirim</button>
      </div>
    </form>
  </div>
</div>

<script>
  const birthdayThreadDom = {
    modal: document.getElementById('birthdayThreadModal'),
    card: document.querySelector('#birthdayThreadModal .birthday-thread-card'),
    closeBtn: document.getElementById('birthdayThreadClose'),
    users: document.getElementById('birthdayThreadUsers'),
    error: document.getElementById('birthdayThreadError'),
    messages: document.getElementById('birthdayThreadMessages'),
    emptyState: document.getElementById('birthdayThreadEmpty'),
    form: document.getElementById('birthdayThreadForm'),
    userIdInput: document.getElementById('birthdayThreadUserId'),
    parentIdInput: document.getElementById('birthdayThreadParentId'),
    replyInfo: document.getElementById('birthdayThreadReplyInfo'),
    cancelReply: document.getElementById('birthdayThreadCancelReply'),
    textarea: document.getElementById('birthdayThreadMessage'),
    submitBtn: document.querySelector('#birthdayThreadForm .birthday-thread-submit'),
    title: document.getElementById('birthdayThreadTitle')
  };

  const birthdayThreadState = {
    threadDate: null,
    birthdayUsers: [],
    activeUserId: null,
    messages: [],
    messageMap: new Map(),
    currentUser: null,
    isOpen: false,
    isLoading: false,
    previousBodyOverflow: ''
  };

  let birthdayThreadAbortController = null;

  function birthdayThreadShowError(message) {
    if (!birthdayThreadDom.error) {
      return;
    }
    if (message) {
      birthdayThreadDom.error.textContent = message;
      birthdayThreadDom.error.style.display = 'block';
    } else {
      birthdayThreadDom.error.textContent = '';
      birthdayThreadDom.error.style.display = 'none';
    }
  }

  function clearBirthdayThreadError() {
    birthdayThreadShowError('');
  }

  function truncateText(value, limit) {
    if (!value) {
      return '';
    }
    const trimmed = String(value).trim();
    if (trimmed.length <= limit) {
      return trimmed;
    }
    const sliceLength = Math.max(limit - 3, 0);
    return trimmed.slice(0, sliceLength) + '...';
  }

  function setBirthdayModalVisible(show) {
    if (!birthdayThreadDom.modal) {
      return;
    }
    if (show) {
      birthdayThreadState.previousBodyOverflow = document.body.style.overflow;
      birthdayThreadDom.modal.style.display = 'flex';
      birthdayThreadDom.modal.setAttribute('aria-hidden', 'false');
      birthdayThreadState.isOpen = true;
      document.body.style.overflow = 'hidden';
      setTimeout(() => {
        if (birthdayThreadDom.textarea && !birthdayThreadDom.textarea.disabled) {
          birthdayThreadDom.textarea.focus();
        }
      }, 200);
    } else {
      birthdayThreadDom.modal.style.display = 'none';
      birthdayThreadDom.modal.setAttribute('aria-hidden', 'true');
      birthdayThreadState.isOpen = false;
      document.body.style.overflow = birthdayThreadState.previousBodyOverflow || '';
      birthdayThreadState.previousBodyOverflow = '';
      clearBirthdayReplyTarget();
      clearBirthdayThreadError();
    }
  }

  function updateBirthdayModalTargetInfo(birthdayUser) {
    const targetName = birthdayUser ? (birthdayUser.full_name || birthdayUser.username || 'Rekan') : null;
    if (birthdayThreadDom.title) {
      birthdayThreadDom.title.textContent = targetName ? `Ucapan untuk ${targetName}` : 'Ucapan Ulang Tahun';
    }
    if (birthdayThreadDom.textarea) {
      birthdayThreadDom.textarea.placeholder = targetName
        ? `Tulis ucapan terbaik untuk ${targetName}...`
        : 'Tulis ucapan terbaikmu untuk rekan yang berulang tahun...';
    }
  }

  function resetBirthdayThreadForm() {
    birthdayThreadDom.form?.reset();
    if (birthdayThreadDom.userIdInput) {
      birthdayThreadDom.userIdInput.value = birthdayThreadState.activeUserId ? String(birthdayThreadState.activeUserId) : '';
    }
    if (birthdayThreadDom.textarea) {
      birthdayThreadDom.textarea.value = '';
    }
    if (birthdayThreadDom.parentIdInput) {
      birthdayThreadDom.parentIdInput.value = '';
    }
    clearBirthdayReplyTarget();
  }

  function clearBirthdayReplyTarget() {
    if (birthdayThreadDom.parentIdInput) {
      birthdayThreadDom.parentIdInput.value = '';
    }
    if (birthdayThreadDom.replyInfo) {
      birthdayThreadDom.replyInfo.textContent = '';
      birthdayThreadDom.replyInfo.style.display = 'none';
    }
    if (birthdayThreadDom.cancelReply) {
      birthdayThreadDom.cancelReply.style.display = 'none';
    }
  }

  function setBirthdayReplyTarget(messageId) {
    if (!messageId) {
      return;
    }
    const raw = birthdayThreadState.messageMap.get(messageId);
    if (!raw) {
      return;
    }
    if (birthdayThreadDom.parentIdInput) {
      birthdayThreadDom.parentIdInput.value = messageId;
    }
    if (birthdayThreadDom.replyInfo) {
      const senderName = raw.sender && raw.sender.name ? raw.sender.name : 'rekan';
      const preview = truncateText(raw.message || '', 80);
      birthdayThreadDom.replyInfo.textContent = preview
        ? `Membalas ${senderName}: "${preview}"`
        : `Membalas ${senderName}`;
      birthdayThreadDom.replyInfo.style.display = 'block';
    }
    if (birthdayThreadDom.cancelReply) {
      birthdayThreadDom.cancelReply.style.display = 'inline-flex';
    }
    if (birthdayThreadDom.textarea && !birthdayThreadDom.textarea.disabled) {
      birthdayThreadDom.textarea.focus();
      const currentLength = birthdayThreadDom.textarea.value.length;
      birthdayThreadDom.textarea.setSelectionRange(currentLength, currentLength);
    }
  }

  function renderBirthdayUsers() {
    if (!birthdayThreadDom.users) {
      return;
    }
    const users = Array.isArray(birthdayThreadState.birthdayUsers) ? birthdayThreadState.birthdayUsers : [];
    birthdayThreadDom.users.innerHTML = '';
    if (!users.length) {
      const empty = document.createElement('div');
      empty.className = 'text-sm text-gray-500 dark:text-gray-300';
      empty.textContent = 'Tidak ada rekan yang berulang tahun hari ini.';
      birthdayThreadDom.users.appendChild(empty);
      return;
    }
    users.forEach((user) => {
      const button = document.createElement('button');
      button.type = 'button';
      const isActive = Number(user.id) === Number(birthdayThreadState.activeUserId);
      button.className = 'birthday-thread-user' + (isActive ? ' birthday-thread-user-active' : '');
      button.dataset.userId = String(user.id);
      const avatar = assetUrl(user.avatar_url);
      const displayName = htmlEscape(user.full_name || user.username || 'Rekan');
      button.innerHTML = `<img src="${avatar}" alt="${displayName}" class="w-7 h-7 rounded-full border border-sky-200 dark:border-slate-700"> <span>${displayName}</span>`;
      attachAvatarFallback(button.querySelector('img'));
      button.addEventListener('click', () => {
        setActiveBirthdayUser(user.id);
      });
      birthdayThreadDom.users.appendChild(button);
    });
  }

  function setActiveBirthdayUser(userId, options = {}) {
    if (!userId) {
      return;
    }
    const normalizedId = Number(userId);
    if (!options.force && normalizedId === birthdayThreadState.activeUserId && !options.refetch) {
      return;
    }
    birthdayThreadState.activeUserId = normalizedId;
    if (birthdayThreadDom.userIdInput) {
      birthdayThreadDom.userIdInput.value = String(normalizedId);
    }
    renderBirthdayUsers();
    fetchBirthdayThread(normalizedId, options);
  }

  function showBirthdayLoading() {
    if (!birthdayThreadDom.messages) {
      return;
    }
    birthdayThreadDom.messages.innerHTML = '<div class="birthday-thread-loading">Memuat ucapan...</div>';
    if (birthdayThreadDom.emptyState) {
      birthdayThreadDom.emptyState.style.display = 'none';
    }
  }

  function buildBirthdayMessageElement(message) {
    const wrapper = document.createElement('div');
    wrapper.className = 'birthday-thread-message';
    wrapper.dataset.messageId = message.id;
    const sender = message.sender || {};
    const avatar = assetUrl(sender.avatar_url);
    const senderName = htmlEscape(sender.name || 'Rekan');
    const senderUsername = sender.username ? htmlEscape(sender.username) : '';
    const createdLabel = htmlEscape(message.created_at_label || message.created_at || '');
    const messageHtml = htmlEscape(message.message || '').replace(/\r?\n/g, '<br>');
    wrapper.innerHTML = `
    <div class="birthday-thread-message-header">
      <img src="${avatar}" class="birthday-thread-message-avatar" alt="${senderName}">
      <div class="birthday-thread-message-meta">
        <span class="font-medium">${senderName}</span>
        ${senderUsername ? `<span class="text-xs text-slate-500 dark:text-slate-400">@${senderUsername}</span>` : ''}
        <span class="text-xs text-slate-400 dark:text-slate-500">${createdLabel}</span>
      </div>
    </div>
    <div class="birthday-thread-message-body">${messageHtml}</div>
    <div class="birthday-thread-message-actions">
      <button type="button" class="birthday-thread-reply-btn" data-message-id="${message.id}">Balas</button>
    </div>
  `;
    const replyBtn = wrapper.querySelector('.birthday-thread-reply-btn');
    if (replyBtn) {
      replyBtn.addEventListener('click', () => setBirthdayReplyTarget(message.id));
    }
    attachAvatarFallback(wrapper.querySelector('.birthday-thread-message-avatar'));
    if (Array.isArray(message.replies) && message.replies.length) {
      const repliesContainer = document.createElement('div');
      repliesContainer.className = 'birthday-thread-replies';
      message.replies.forEach((child) => {
        repliesContainer.appendChild(buildBirthdayMessageElement(child));
      });
      wrapper.appendChild(repliesContainer);
    }
    return wrapper;
  }

  function renderBirthdayMessages(messages, options = {}) {
    if (!birthdayThreadDom.messages) {
      return;
    }
    const list = Array.isArray(messages) ? messages : [];
    birthdayThreadDom.messages.innerHTML = '';
    if (!list.length) {
      if (birthdayThreadDom.emptyState) {
        birthdayThreadDom.emptyState.style.display = 'block';
      }
      return;
    }
    if (birthdayThreadDom.emptyState) {
      birthdayThreadDom.emptyState.style.display = 'none';
    }
    list.forEach((msg) => {
      birthdayThreadDom.messages.appendChild(buildBirthdayMessageElement(msg));
    });
    const focusId = options.focusMessageId;
    if (focusId) {
      const focusEl = birthdayThreadDom.messages.querySelector(`[data-message-id="${focusId}"]`);
      if (focusEl) {
        focusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
    }
    birthdayThreadDom.messages.scrollTop = birthdayThreadDom.messages.scrollHeight;
  }

  function indexBirthdayMessages(messages) {
    if (!(birthdayThreadState.messageMap instanceof Map)) {
      birthdayThreadState.messageMap = new Map();
    }
    birthdayThreadState.messageMap.clear();
    const walk = (list) => {
      list.forEach((entry) => {
        if (!entry || !entry.id) {
          return;
        }
        birthdayThreadState.messageMap.set(entry.id, entry);
        if (Array.isArray(entry.replies) && entry.replies.length) {
          walk(entry.replies);
        }
      });
    };
    if (Array.isArray(messages)) {
      walk(messages);
    }
  }

  function setBirthdayFormEnabled(enabled) {
    const allow = Boolean(enabled);
    if (birthdayThreadDom.textarea) {
      birthdayThreadDom.textarea.disabled = !allow;
    }
    if (birthdayThreadDom.submitBtn) {
      birthdayThreadDom.submitBtn.disabled = !allow;
    }
  }

  function fetchBirthdayThread(userId, options = {}) {
    if (!userId || !birthdayThreadDom.messages) {
      return;
    }
    if (birthdayThreadAbortController) {
      birthdayThreadAbortController.abort();
    }
    const controller = new AbortController();
    birthdayThreadAbortController = controller;
    birthdayThreadState.isLoading = true;
    showBirthdayLoading();
    clearBirthdayThreadError();
    setBirthdayFormEnabled(false);

    fetch(`./app/action/birthday_thread_fetch.php?birthday_user_id=${encodeURIComponent(userId)}`, { signal: controller.signal })
      .then((res) => res.json())
      .then((response) => {
        if (controller.signal.aborted) {
          return;
        }
        birthdayThreadAbortController = null;
        birthdayThreadState.isLoading = false;

        if (!response || response.success !== true) {
          const errMsg = response && response.error ? response.error : 'Gagal memuat data ulang tahun.';
          throw new Error(errMsg);
        }

        const data = response.data || {};
        birthdayThreadState.threadDate = data.thread_date || birthdayThreadState.threadDate;
        birthdayThreadState.currentUser = data.current_user || birthdayThreadState.currentUser;

        if (data.birthday_user && typeof data.birthday_user.id !== 'undefined') {
          const normalizedId = Number(data.birthday_user.id);
          const enriched = {
            id: normalizedId,
            full_name: data.birthday_user.full_name || data.birthday_user.username || 'Rekan',
            username: data.birthday_user.username || null,
            avatar_url: data.birthday_user.avatar_url || null
          };
          const existingIndex = birthdayThreadState.birthdayUsers.findIndex((item) => Number(item.id) === normalizedId);
          if (existingIndex >= 0) {
            birthdayThreadState.birthdayUsers[existingIndex] = { ...birthdayThreadState.birthdayUsers[existingIndex], ...enriched };
          } else {
            birthdayThreadState.birthdayUsers.push(enriched);
          }
          birthdayThreadState.activeUserId = normalizedId;
        }

        const messages = Array.isArray(data.messages) ? data.messages : [];
        birthdayThreadState.messages = messages;
        indexBirthdayMessages(messages);
        renderBirthdayUsers();
        updateBirthdayModalTargetInfo(data.birthday_user || null);
        renderBirthdayMessages(messages, { focusMessageId: options.focusMessageId });
        setBirthdayFormEnabled(true);
        clearBirthdayReplyTarget();
        clearBirthdayThreadError();
      })
      .catch((error) => {
        if (controller.signal.aborted) {
          return;
        }
        birthdayThreadAbortController = null;
        birthdayThreadState.isLoading = false;
        birthdayThreadShowError(error.message || 'Gagal memuat data ulang tahun.');
        if (birthdayThreadDom.messages) {
          birthdayThreadDom.messages.innerHTML = '';
        }
        if (birthdayThreadDom.emptyState) {
          birthdayThreadDom.emptyState.style.display = 'none';
        }
        setBirthdayFormEnabled(false);
      });
  }

  function openBirthdayThreadModalFromNotification(payload) {
    if (!birthdayThreadDom.modal) {
      return;
    }
    const users = Array.isArray(payload && payload.birthday_users) ? payload.birthday_users : [];
    birthdayThreadState.threadDate = payload && payload.thread_date ? payload.thread_date : null;
    birthdayThreadState.birthdayUsers = users.map((user) => ({
      id: Number(user.id),
      full_name: user.full_name || user.username || 'Rekan',
      username: user.username || null,
      avatar_url: user.avatar_url || null
    }));
    birthdayThreadState.messages = [];
    birthdayThreadState.messageMap = new Map();
    birthdayThreadState.activeUserId = birthdayThreadState.birthdayUsers.length ? birthdayThreadState.birthdayUsers[0].id : null;

    clearBirthdayThreadError();
    resetBirthdayThreadForm();
    if (birthdayThreadDom.messages) {
      birthdayThreadDom.messages.innerHTML = '';
    }
    if (birthdayThreadDom.emptyState) {
      birthdayThreadDom.emptyState.style.display = birthdayThreadState.activeUserId ? 'none' : 'block';
    }
    renderBirthdayUsers();
    const activeEntry = birthdayThreadState.birthdayUsers.find((entry) => Number(entry.id) === Number(birthdayThreadState.activeUserId)) || null;
    updateBirthdayModalTargetInfo(activeEntry);
    setBirthdayFormEnabled(false);
    setBirthdayModalVisible(true);

    if (birthdayThreadState.activeUserId) {
      showBirthdayLoading();
      fetchBirthdayThread(birthdayThreadState.activeUserId, { force: true });
    }
  }

  birthdayThreadDom.closeBtn?.addEventListener('click', () => setBirthdayModalVisible(false));
  birthdayThreadDom.modal?.addEventListener('click', (event) => {
    if (event.target === birthdayThreadDom.modal) {
      setBirthdayModalVisible(false);
    }
  });
  birthdayThreadDom.cancelReply?.addEventListener('click', () => clearBirthdayReplyTarget());

  if (birthdayThreadDom.form) {
    birthdayThreadDom.form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!birthdayThreadState.activeUserId) {
        return;
      }
      const messageValue = birthdayThreadDom.textarea ? birthdayThreadDom.textarea.value.trim() : '';
      if (!messageValue) {
        birthdayThreadShowError('Pesan tidak boleh kosong.');
        birthdayThreadDom.textarea?.focus();
        return;
      }

      clearBirthdayThreadError();

      const submitBtn = birthdayThreadDom.submitBtn;
      if (submitBtn && !submitBtn.dataset.originalText) {
        submitBtn.dataset.originalText = submitBtn.textContent;
      }
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Mengirim...';
      }
      if (birthdayThreadDom.textarea) {
        birthdayThreadDom.textarea.disabled = true;
      }

      const payload = new URLSearchParams();
      payload.append('birthday_user_id', String(birthdayThreadState.activeUserId));
      if (birthdayThreadDom.parentIdInput && birthdayThreadDom.parentIdInput.value) {
        payload.append('parent_id', birthdayThreadDom.parentIdInput.value);
      }
      payload.append('message', messageValue);

      fetch('./app/action/birthday_thread_post.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
      })
        .then((res) => res.json())
        .then((response) => {
          if (!response || response.success !== true) {
            const err = response && response.error ? response.error : 'Gagal mengirim ucapan.';
            throw new Error(err);
          }
          if (birthdayThreadDom.textarea) {
            birthdayThreadDom.textarea.value = '';
          }
          clearBirthdayReplyTarget();
          fetchBirthdayThread(birthdayThreadState.activeUserId, {
            focusMessageId: response.message ? response.message.id : undefined,
            force: true
          });
        })
        .catch((error) => {
          birthdayThreadShowError(error.message || 'Gagal mengirim ucapan.');
        })
        .finally(() => {
          if (birthdayThreadDom.textarea) {
            birthdayThreadDom.textarea.disabled = false;
            birthdayThreadDom.textarea.focus();
          }
          if (submitBtn) {
            submitBtn.disabled = false;
            if (submitBtn.dataset.originalText) {
              submitBtn.textContent = submitBtn.dataset.originalText;
            }
          }
        });
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && birthdayThreadState.isOpen) {
      setBirthdayModalVisible(false);
    }
  });

  const userMenuToggle = document.getElementById('userMenuToggle');
  userMenuToggle?.addEventListener('click', () => {
    document.getElementById('userMenu').classList.toggle('hidden');
  });
  const hamburger = document.getElementById('sidebarToggle');
  hamburger?.addEventListener('click', function () {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !overlay) return;
    function isMobile() { return window.innerWidth < 768; }
    if (!isMobile()) return;
    if (sidebar.classList.contains('hidden')) {
      sidebar.classList.remove('hidden');
      void sidebar.offsetWidth;
      sidebar.classList.add('show', 'flex');
      overlay.classList.remove('pointer-events-none');
      overlay.classList.add('opacity-100');
      overlay.classList.remove('opacity-0');
      document.body.style.overflow = 'hidden';
    } else {
      sidebar.classList.remove('show');
      overlay.classList.remove('opacity-100');
      overlay.classList.add('opacity-0');
      setTimeout(() => {
        sidebar.classList.add('hidden');
        sidebar.classList.remove('flex');
        overlay.classList.add('pointer-events-none');
        document.body.style.overflow = '';
      }, 300);
    }
  });


  document.querySelectorAll('.user-menu-ajax').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var page = this.getAttribute('data-page');
      if (!page) return;
      var main = document.getElementById('main-content');
      if (!main) return window.location.href = page;
      main.innerHTML = '<div class="p-8 text-center text-slate-400">Loading...</div>';
      fetch(page)
        .then(r => r.text())
        .then(html => {
          main.innerHTML = html;
          document.getElementById('userMenu').classList.add('hidden');
        })
        .catch(() => { main.innerHTML = '<div class="p-8 text-center text-red-400">Gagal memuat halaman.</div>'; });
    });
  });


  function fetchAndRenderNotifications(options = {}) {
    const dropdown = document.getElementById('notification-dropdown');
    const list = document.getElementById('notifications-list');
    const notificationBtn = document.getElementById('notification-btn');
    if (!dropdown || !list || !notificationBtn) {
      return;
    }

    const showLoading = options.showLoading === true;
    if (showLoading) {
      list.innerHTML = '<div class="p-3 text-gray-500">Memuat notifikasi...</div>';
    }

    const notifyUrlObj = new URL('./app/action/get_notifications.php', window.location.href);
    notifyUrlObj.searchParams.set('_', String(Date.now()));
    const notifyUrl = notifyUrlObj.toString();

    const fetchNotificationData = () => {
      return fetch(notifyUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then((res) => {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status + ' @ ' + notifyUrl);
        }
        return res.json();
      });
    };

    fetchNotificationData()
      .then(data => {
        const meta = data.meta || {};
        const warn = Boolean(meta.overdue_count);
        notificationBtn.classList.toggle('bell-alert', warn);
        notificationBtn.dataset.hasOverdue = warn ? '1' : '0';

        const badgeEl = notificationBtn.querySelector('.notification-count');
        if (badgeEl) {
          badgeEl.classList.toggle('alert', warn);
          if (typeof meta.badge_total === 'number') {
            badgeEl.textContent = meta.badge_total;
            badgeEl.style.display = meta.badge_total > 0 ? '' : 'none';
          }
        } else if (typeof meta.badge_total === 'number' && meta.badge_total > 0) {
          const badge = document.createElement('span');
          badge.className = 'notification-count' + (warn ? ' alert' : '');
          badge.textContent = meta.badge_total;
          notificationBtn.appendChild(badge);
        }

        const formatDateTime = (value, opts = { dateStyle: 'short', timeStyle: 'short' }) => {
          if (!value) return '-';
          const dt = new Date(value);
          if (Number.isNaN(dt.getTime())) return value;
          try {
            return dt.toLocaleString('id-ID', opts);
          } catch (err) {
            return dt.toLocaleString();
          }
        };

        list.innerHTML = '';
        const items = data.notifications || [];
        if (items.length === 0) {
          list.innerHTML = '<div class="p-3 text-gray-500">Tidak ada notifikasi pending</div>';
          return;
        }
        items.forEach(n => {
          const item = document.createElement('div');
          item.className = 'p-3 border-b border-slate-200 dark:border-white/10';
          if (n.kind === 'loan_group') {
            const tools = Array.isArray(n.tools) ? n.tools : [];
            const ids = Array.isArray(n.ids) ? n.ids : [];
            const idsJson = htmlEscape(JSON.stringify(ids));
            const toolCount = tools.length;
            const permitLabel = n.permit_type === 'return' ? 'Pengembalian' : 'Peminjaman';
            const fromLabel = n.permit_type === 'return'
              ? `Pengembalian dari: ${htmlEscape(n.from_user_name || 'User Unknown')}`
              : `Dari: ${htmlEscape(n.from_user_name || 'User Unknown')} → ${htmlEscape(n.to_user_name || 'User Unknown')}`;

            let toolListHtml = tools.map(t => {
              const proofSrc = t.photo_proof_path ? resolveProofAsset(t.photo_proof_path) : null;
              const imgHtml = proofSrc
                ? `<img src="${proofSrc}" class="w-10 h-10 object-cover rounded border flex-shrink-0" alt="bukti">`
                : `<div class="w-10 h-10 rounded border bg-slate-100 dark:bg-slate-700 flex items-center justify-center text-slate-400 flex-shrink-0"><i class="fas fa-wrench"></i></div>`;
              const locHtml = t.location ? `<div class="text-gray-400"><i class="fas fa-map-marker-alt mr-1"></i>${htmlEscape(t.location)}</div>` : '';
              return `<div class="flex items-center gap-2 py-1">
              ${imgHtml}
              <div class="text-xs leading-tight">
                <div class="font-medium">${htmlEscape(t.tool_name || 'Peralatan')}</div>
                <div class="text-gray-400">${htmlEscape(t.tool_code || '-')}</div>
                ${locHtml}
              </div>
            </div>`;
            }).join('');

            const dateHtml = tools[0] && tools[0].start_date
              ? `<div class="text-xs text-gray-400">${new Date(tools[0].start_date).toLocaleDateString('id-ID')} → ${new Date(tools[0].end_date).toLocaleDateString('id-ID')}</div>`
              : '';

            item.innerHTML = `
              <div>
                <div class="flex items-center gap-2 mb-1">
                  <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center text-blue-600 dark:text-blue-200 text-sm"><i class="fas fa-wrench"></i></div>
                  <div class="flex-1">
                    <div class="font-medium text-sm">${permitLabel} ${toolCount} alat</div>
                    <div class="text-xs text-gray-500">${fromLabel}</div>
                  </div>
                </div>
                ${dateHtml}
                ${n.location ? `<div class="text-xs text-gray-500 mb-1"><i class="fas fa-map-marker-alt mr-1"></i>Lokasi: ${htmlEscape(n.location)}</div>` : ''}
                ${n.reason ? `<div class="text-xs italic text-gray-500 mb-1">Tujuan: ${htmlEscape(n.reason)}</div>` : ''}
                <div class="max-h-32 overflow-y-auto border rounded dark:border-white/10 px-2 my-1">${toolListHtml}</div>
                <div class="mt-2 flex gap-2">
                  <button class="approve-loan-group px-3 py-1 text-white bg-green-600 rounded text-xs" data-ids="${idsJson}" data-type="${n.permit_type || ''}" data-info="${htmlEscape(JSON.stringify({ borrower: n.from_user_name || '-', date: (tools[0] && tools[0].start_date ? new Date(tools[0].start_date).toLocaleDateString('id-ID') + ' → ' + new Date(tools[0].end_date).toLocaleDateString('id-ID') : '-'), location: n.location || '-', tools: tools.map(t => ({ name: t.tool_name || '-', code: t.tool_code || '-' })) }))}"  ><i class="fas fa-check-circle mr-1"></i>Approve Semua</button>
                  <button class="reject-loan-group px-3 py-1 text-white bg-red-600 rounded text-xs" data-ids="${idsJson}"><i class="fas fa-times-circle mr-1"></i>Reject Semua</button>
                </div>
              </div>`;
          } else if (n.kind === 'loan') {
            const proofSrc = n.photo_proof_path ? resolveProofAsset(n.photo_proof_path) : null;
            const imgHtml = proofSrc
              ? `<img src="${proofSrc}" class="w-16 h-16 object-cover rounded border" alt="bukti">`
              : `<div class="w-16 h-16 rounded border bg-slate-100 dark:bg-slate-700 flex items-center justify-center text-slate-400 text-2xl"><i class="fas fa-wrench"></i></div>`;
            item.innerHTML = `
              <div class="flex gap-3">
                ${imgHtml}
                <div class="flex-1">
                  <div class="font-medium">${n.tool_name || 'Peralatan'} (${n.tool_code || '-'})</div>
                  <div class="text-sm text-gray-500">${n.permit_type === 'return' ? `Pengembalian dari: ${n.from_user_name || 'User Unknown'}` : `Dari: ${n.from_user_name || 'User Unknown'} → ${n.to_user_name || 'User Unknown'}`}</div>
                  ${n.start_date ? `<div class=\"text-xs text-gray-400\">${new Date(n.start_date).toLocaleDateString('id-ID')} → ${new Date(n.end_date).toLocaleDateString('id-ID')}</div>` : ''}
                  ${n.reason ? `<div class=\"text-xs italic text-gray-500\">Tujuan: ${n.reason}</div>` : ''}
                  <div class="mt-2 flex gap-2">
                    <button class="approve-loan px-2 py-1 text-white bg-green-600 rounded text-xs" data-id="${n.id}" data-type="${n.permit_type || ''}" data-tool="${htmlEscape((n.tool_name || 'Tools') + ' (' + (n.tool_code || '-') + ')')}" data-info="${htmlEscape(JSON.stringify({ borrower: n.permit_type === 'return' ? (n.from_user_name || '-') : (n.to_user_name || '-'), date: n.start_date ? new Date(n.start_date).toLocaleDateString('id-ID') + ' → ' + new Date(n.end_date).toLocaleDateString('id-ID') : '-', location: n.location || '-', tools: [{ name: n.tool_name || '-', code: n.tool_code || '-' }] }))}"  ><i class="fas fa-check-circle mr-1"></i>Approve</button>
                    <button class="reject-loan px-2 py-1 text-white bg-red-600 rounded text-xs" data-id="${n.id}"><i class="fas fa-times-circle mr-1"></i>Reject</button>
                  </div>
                </div>
              </div>`;
          } else if (n.kind === 'birthday') {
            const users = Array.isArray(n.birthday_users) ? n.birthday_users : [];
            const total = users.length;
            const nameList = users.map(user => htmlEscape(user.full_name || user.username || 'Rekan'));
            let titleText;
            if (total > 1) {
              titleText = `${total} rekan berulang tahun hari ini`;
            } else if (total === 1) {
              titleText = `${nameList[0]} berulang tahun hari ini`;
            } else {
              titleText = 'Rayakan rekan yang berulang tahun';
            }

            let descriptionText = '';
            if (total === 1) {
              descriptionText = `Rayakan bersama ${nameList[0]}.`;
            } else if (total > 1) {
              const previewNames = nameList.slice(0, 3);
              descriptionText = previewNames.join(', ');
              if (total > 3) {
                descriptionText += `, dan ${total - 3} lainnya`;
              }
            }

            const previewUsers = users.slice(0, 3);
            const previewHtml = previewUsers.map(user => {
              const avatar = assetUrl(user.avatar_url);
              const displayName = htmlEscape(user.full_name || user.username || 'Rekan');
              return `<span class="inline-flex items-center gap-2 text-xs text-blue-700 dark:text-blue-200 bg-blue-50 dark:bg-white/5 border border-blue-200 dark:border-white/10 px-2 py-1 rounded-full"><img src="${avatar}" alt="${displayName}" class="birthday-thread-inline-avatar w-6 h-6 rounded-full border border-blue-200 dark:border-white/10">${displayName}</span>`;
            }).join('');
            const extraCount = Math.max(total - previewUsers.length, 0);

            item.innerHTML = `
              <div class="flex gap-3">
                <div class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center text-blue-600 dark:text-blue-200 text-xl"><i class="fas fa-cake-candles"></i></div>
                <div class="flex-1">
                  <div class="font-semibold text-blue-600 dark:text-blue-200">${titleText}</div>
                  ${descriptionText ? `<div class="text-sm text-gray-600 dark:text-gray-300">${descriptionText}</div>` : '<div class="text-sm text-gray-600 dark:text-gray-300">Kirim ucapan terbaikmu untuk mereka.</div>'}
                  <div class="mt-2 flex flex-wrap gap-2">${previewHtml}${extraCount > 0 ? `<span class="text-xs text-blue-600 dark:text-blue-200">+${extraCount} lainnya</span>` : ''}</div>
                  <button type="button" class="birthday-thread-open mt-3 inline-flex items-center gap-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-400 rounded-full px-3 py-1.5">Buka ruang ucapan<span aria-hidden="true">→</span></button>
                </div>
              </div>`;

            const openBtn = item.querySelector('.birthday-thread-open');
            if (openBtn) {
              openBtn.addEventListener('click', (event) => {
                event.preventDefault();
                document.getElementById('notification-dropdown')?.classList.add('hidden');
                openBirthdayThreadModalFromNotification({
                  thread_date: n.thread_date || null,
                  birthday_users: users
                });
              });
            }
            item.querySelectorAll('.birthday-thread-inline-avatar').forEach((imgEl) => attachAvatarFallback(imgEl));
          } else if (n.kind === 'schedule') {
            const sd = n.schedule_date ? new Date(n.schedule_date).toLocaleDateString('id-ID') : '-';
            item.innerHTML = `
              <div class="flex gap-3">
                <div class="w-10 h-10 rounded bg-blue-100 flex items-center justify-center text-blue-600"><i class="fas fa-calendar-days"></i></div>
                <div class="flex-1">
                  <div class="font-medium">Jadwal Hari Ini</div>
                  <div class="text-xs text-gray-500">${sd} • Tempat: ${n.destination || '-'}</div>
                  ${n.details ? `<div class=\"text-xs italic text-gray-500\">${n.details}</div>` : ''}
                  <div class="text-[10px] text-gray-400 mt-1">Dibuat oleh ${n.created_by || 'Administrator'}</div>
                </div>
              </div>`;
          } else if (n.kind === 'overdue') {
            const borrowedLabel = n.borrowed_at ? formatDateTime(n.borrowed_at) : null;
            const dueLabel = n.due_at ? formatDateTime(n.due_at, { dateStyle: 'short', timeStyle: 'short' }) : null;
            const overdueText = n.over_text || 'Telah melewati batas pinjam 3 hari';
            item.innerHTML = `
              <div class="flex gap-3">
                <div class="notification-warning-icon">!</div>
                <div class="flex-1">
                  <div class="font-semibold text-amber-600">Peringatan Pengembalian Barang</div>
                  <div class="text-sm text-gray-600 dark:text-gray-300">${htmlEscape(n.tool_name || 'Tools')} <span class="text-xs text-gray-400">(${htmlEscape(n.tool_code || '-')})</span></div>
                  ${n.borrower_name ? `<div class="text-xs text-gray-500">Peminjam: ${htmlEscape(n.borrower_name)}</div>` : ''}
                  ${borrowedLabel ? `<div class="text-xs text-gray-400">Dipinjam: ${borrowedLabel}</div>` : ''}
                  ${dueLabel ? `<div class="text-xs text-gray-400">Jatuh tempo: ${dueLabel}</div>` : ''}
                  <div class="mt-1 text-xs font-medium text-red-500">${htmlEscape(overdueText)}</div>
                </div>
              </div>`;
          } else if (n.kind === 'password_reset') {
            item.innerHTML = `
              <div class="flex gap-3">
                <div class="w-10 h-10 rounded bg-orange-100 flex items-center justify-center text-orange-600"><i class="fas fa-lock"></i></div>
                <div class="flex-1">
                  <div class="font-medium">Permintaan Reset Password</div>
                  <div class="text-sm text-gray-500">${htmlEscape(n.full_name || '-')}</div>
                  ${n.username ? `<div class="text-xs text-gray-400">@${htmlEscape(n.username)}</div>` : ''}
                  ${n.email ? `<div class="text-xs text-gray-400">${htmlEscape(n.email)}</div>` : ''}
                  <div class="mt-2">
                    <a href="?page=inbox&section=password&status=pending" class="inline-flex items-center text-xs text-orange-600 hover:underline">Buka Inbox</a>
                  </div>
                </div>
              </div>`;
          } else if (n.kind === 'material_request_edited') {
            item.innerHTML = `
              <div class="flex gap-3">
                <div class="w-10 h-10 rounded bg-amber-100 flex items-center justify-center text-amber-600"><i class="fas fa-edit"></i></div>
                <div class="flex-1">
                  <div class="font-medium">Request Diedit oleh Sales</div>
                  <div class="text-sm text-gray-500">${htmlEscape(n.edit_note || 'Ada perubahan pada request material Anda')}</div>
                  <div class="mt-2">
                    <a href="?page=request-material" class="inline-flex items-center text-xs text-amber-600 hover:underline">Lihat Request</a>
                  </div>
                </div>
              </div>`;
          } else if (n.kind === 'material_request') {
            const statusBg = n.status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-blue-100 text-blue-700';
            const statusLabel = n.status === 'pending' ? 'Pending' : 'Under Review';
            item.innerHTML = `
              <div class="flex gap-3">
                <div class="w-10 h-10 rounded bg-purple-100 flex items-center justify-center text-purple-600"><i class="fas fa-box"></i></div>
                <div class="flex-1">
                  <div class="font-medium">Request Material</div>
                  <div class="text-sm text-gray-500">Dari: ${htmlEscape(n.requester_name || 'Technician')}</div>
                  <div class="text-xs"><span class="px-2 py-1 rounded-full ${statusBg}">${statusLabel}</span></div>
                  <div class="mt-2">
                    <a href="?page=request-material" class="inline-flex items-center text-xs text-purple-600 hover:underline">Lihat Request</a>
                  </div>
                </div>
              </div>`;
          } else if (n.kind === 'material_delivery') {
            item.innerHTML = `
              <div class="flex gap-3">
                <div class="w-10 h-10 rounded bg-orange-100 flex items-center justify-center text-orange-600"><i class="fas fa-truck"></i></div>
                <div class="flex-1">
                  <div class="font-medium">Material Siap Antar</div>
                  <div class="text-sm text-gray-500">Dari: ${htmlEscape(n.requester_name || 'Technician')}</div>
                  <div class="text-xs"><span class="px-2 py-1 rounded-full bg-orange-100 text-orange-700">Siap Pickup</span></div>
                  <div class="mt-2">
                    <a href="?page=inbox&section=deliveries" class="inline-flex items-center text-xs text-orange-600 hover:underline">Lihat Pengantaran</a>
                  </div>
                </div>
              </div>`;
          } else if (n.kind === 'cuti') {
            const sd = n.start_date ? new Date(n.start_date).toLocaleDateString('id-ID') : '-';
            const ed = n.end_date ? new Date(n.end_date).toLocaleDateString('id-ID') : '-';
            const statusMap = { pending: 'Menunggu Manager', manager_approved: 'Menunggu Admin', admin_approved: 'Menunggu Direktur' };
            const statusLabel = statusMap[n.status] || n.status;
            const statusColor = n.status === 'pending' ? 'bg-yellow-100 text-yellow-700' : (n.status === 'admin_approved' ? 'bg-blue-100 text-blue-700' : 'bg-indigo-100 text-indigo-700');
            item.innerHTML = `
              <div class="flex gap-3">
                <div class="w-10 h-10 rounded bg-indigo-100 flex items-center justify-center text-indigo-600"><i class="fas fa-umbrella-beach"></i></div>
                <div class="flex-1">
                  <div class="font-medium">Pengajuan Cuti</div>
                  <div class="text-sm text-gray-500">${htmlEscape(n.user_name || 'Staff')} &bull; ${n.total_days || '?'} hari</div>
                  <div class="text-xs text-gray-400">${sd} → ${ed}</div>
                  ${n.reason ? '<div class="text-xs italic text-gray-500 mt-0.5">Alasan: ' + htmlEscape(n.reason) + '</div>' : ''}
                  <div class="text-xs mt-1"><span class="px-2 py-0.5 rounded-full ${statusColor}">${statusLabel}</span></div>
                  <div class="mt-2">
                    <a href="?page=leave" class="inline-flex items-center text-xs text-indigo-600 hover:underline">Buka Halaman Cuti →</a>
                  </div>
                </div>
              </div>`;
          } else {
            const proofSrc = resolveProofAsset(n.proof_path);
            const uname = n.user_name || 'User';
            const isAttReq = n.kind === 'attendance_request';
            const type = isAttReq ? (n.request_type === 'missed_checkout' ? 'Request Absen Pulang' : 'Request Absen Masuk') : (n.type || '').toUpperCase();
            const dateDisplay = isAttReq
              ? (n.attendance_date ? new Date(n.attendance_date).toLocaleDateString('id-ID') : '-')
              : ((n.start_date ? new Date(n.start_date).toLocaleDateString('id-ID') : '-') + ' → ' + (n.end_date ? new Date(n.end_date).toLocaleDateString('id-ID') : '-'));
            item.innerHTML = `
              <div class="flex gap-3">
    ${proofSrc ? `<img src="${proofSrc}" class="w-16 h-16 object-cover rounded border" alt="bukti">` : ''}
                <div class="flex-1">
                  <div class="font-medium">${uname} <span class="text-xs text-gray-500">(${type})</span></div>
                  <div class="text-xs text-gray-500">${dateDisplay}</div>
                  ${n.reason ? `<div class=\"text-xs italic text-gray-500\">Alasan: ${n.reason}</div>` : ''}
                  ${isAttReq && n.today_plan ? `<div class=\"text-xs text-gray-500\">Plan: ${n.today_plan}</div>` : ''}
                  ${isAttReq && n.location_name ? `<div class=\"text-xs text-gray-500\"><i class=\"fas fa-map-marker-alt mr-1\"></i>${n.location_name}</div>` : ''}
                  <div class="mt-2 flex gap-2">
                    <button class="approve-leave px-2 py-1 text-white bg-green-600 rounded text-xs" data-id="${n.id}" data-source="${n.kind || 'leave'}"><i class="fas fa-check-circle mr-1"></i>Approve</button>
                    <button class="reject-leave px-2 py-1 text-white bg-red-600 rounded text-xs" data-id="${n.id}" data-source="${n.kind || 'leave'}"><i class="fas fa-times-circle mr-1"></i>Reject</button>
                  </div>
                </div>
              </div>`;
            const proofImg = item.querySelector('img');
            if (proofImg) {
              proofImg.src = proofSrc;
            }
          }
          list.appendChild(item);
          item.querySelectorAll('img').forEach(function (imgEl) {
            const rawSrc = imgEl.getAttribute('src') || '';
            if (!rawSrc || /\$\{[^}]+\}/.test(rawSrc) || rawSrc === '#') {
              imgEl.setAttribute('src', assetUrl(''));
            }
          });
        });

        document.querySelectorAll('.approve-loan').forEach(btn => btn.addEventListener('click', function () { var info = null; try { info = JSON.parse(this.dataset.info || 'null'); } catch (e) { } handleLoanAction(this.dataset.id, 'approve', this.dataset.type, this.dataset.tool, info); }));
        document.querySelectorAll('.reject-loan').forEach(btn => btn.addEventListener('click', function () { handleLoanAction(this.dataset.id, 'reject'); }));
        document.querySelectorAll('.approve-loan-group').forEach(btn => btn.addEventListener('click', function () { var info = null; try { info = JSON.parse(this.dataset.info || 'null'); } catch (e) { } handleBulkLoanAction(JSON.parse(this.dataset.ids), 'approve', this.dataset.type, info); }));
        document.querySelectorAll('.reject-loan-group').forEach(btn => btn.addEventListener('click', function () { handleBulkLoanAction(JSON.parse(this.dataset.ids), 'reject'); }));
        document.querySelectorAll('.approve-leave').forEach(btn => btn.addEventListener('click', function () { handleLeaveAction(this.dataset.id, 'approve', this.dataset.source); }));
        document.querySelectorAll('.reject-leave').forEach(btn => btn.addEventListener('click', function () { handleLeaveAction(this.dataset.id, 'reject', this.dataset.source); }));
      })
      .catch(err => {
        console.error('Notification fetch failed:', err, 'URL:', notifyUrl, 'ONLINE:', navigator.onLine);
        if (!dropdown.classList.contains('hidden') || showLoading) {
          list.innerHTML = '<div class="p-3 text-red-500">Gagal memuat notifikasi.</div>';
        }
      });
  }


  document.getElementById('notification-btn')?.addEventListener('click', function (e) {
    e.stopPropagation();
    const dropdown = document.getElementById('notification-dropdown');
    if (!dropdown) {
      return;
    }
    const willOpen = dropdown.classList.contains('hidden');
    dropdown.classList.toggle('hidden');
    if (willOpen) {
      fetchAndRenderNotifications({ showLoading: true });
    }
  });

  function _doLoanApprovePost(permitId, action) {
    fetch('./app/action/handle_loan_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `id=${permitId}&action=${action}`
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          const dropdown = document.getElementById('notification-dropdown');
          dropdown.classList.add('hidden');
          setTimeout(() => { document.getElementById('notification-btn').click(); }, 100);
          if (typeof window.refreshAllToolsData === 'function') { setTimeout(() => { window.refreshAllToolsData(); }, 200); }
          showHeaderToast('Permintaan berhasil ' + (action === 'approve' ? 'disetujui' : 'ditolak'), action === 'approve' ? 'success' : 'warning');
          if (window._globalSocket && window._globalSocket.connected) { window._globalSocket.emit('broadcast_notification', { type: 'loan_action' }); }
        } else {
          showHeaderToast('Gagal memproses: ' + data.message, 'error');
        }
      })
      .catch(err => showHeaderToast('Error: ' + err.message, 'error'));
  }

  function handleLoanAction(permitId, action, permitType, toolName, infoData) {
    var needsPhoto = (action === 'approve' && ['loan', 'handover', 'project'].indexOf(permitType) !== -1);
    if (needsPhoto) {
      _showHeaderPhotoModal(permitId, toolName, null, infoData);
      return;
    }
    _doLoanApprovePost(permitId, action);
  }

  function handleBulkLoanAction(ids, action, permitType, infoData) {
    var needsPhoto = (action === 'approve' && ['loan', 'handover', 'project'].indexOf(permitType) !== -1);
    if (needsPhoto) {
      _showHeaderPhotoModal(null, 'Bulk Approve ' + ids.length + ' alat', ids, infoData);
      return;
    }

    const formData = new URLSearchParams();
    ids.forEach(id => formData.append('ids[]', id));
    formData.append('action', action);

    fetch('./app/action/handle_loan_action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: formData.toString()
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          const dropdown = document.getElementById('notification-dropdown');
          dropdown.classList.add('hidden');
          setTimeout(() => { document.getElementById('notification-btn').click(); }, 100);
          if (typeof window.refreshAllToolsData === 'function') { setTimeout(() => { window.refreshAllToolsData(); }, 200); }
          showHeaderToast(data.message || ('Semua permintaan berhasil ' + (action === 'approve' ? 'disetujui' : 'ditolak')), action === 'approve' ? 'success' : 'warning');
          if (window._globalSocket && window._globalSocket.connected) { window._globalSocket.emit('broadcast_notification', { type: 'loan_action' }); }
        } else {
          showHeaderToast('Gagal memproses: ' + data.message, 'error');
        }
      })
      .catch(err => {
        showHeaderToast('Error: ' + err.message, 'error');
      });
  }

  function _compressPhotoFile(file, maxW, quality) {
    maxW = maxW || 1280; quality = quality || 0.7;
    if (!file || !file.type.startsWith('image/') || file.size < 204800) return Promise.resolve(file);
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var w = img.width, h = img.height;
        if (w > maxW) { h = Math.round(h * maxW / w); w = maxW; }
        if (h > maxW) { w = Math.round(w * maxW / h); h = maxW; }
        var c = document.createElement('canvas');
        c.width = w; c.height = h;
        c.getContext('2d').drawImage(img, 0, 0, w, h);
        c.toBlob(function (b) {
          URL.revokeObjectURL(img.src);
          if (!b) return resolve(file);
          resolve(new File([b], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' }));
        }, 'image/jpeg', quality);
      };
      img.onerror = function () { resolve(file); };
      img.src = URL.createObjectURL(file);
    });
  }

  var _headerPhotoPermitId = null;
  var _headerPhotoBulkIds = null;

  function _showHeaderPhotoModal(permitId, toolName, bulkIds, infoData) {
    _headerPhotoPermitId = permitId;
    _headerPhotoBulkIds = bulkIds;
    var dropdown = document.getElementById('notification-dropdown');
    if (dropdown) dropdown.classList.add('hidden');

    // Always remove and recreate to apply current dark/light mode
    var oldModal = document.getElementById('headerPhotoModal');
    if (oldModal) oldModal.remove();

    var modal = document.createElement('div');
    modal.id = 'headerPhotoModal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;animation:_hpmFadeIn 0.18s ease;';

    var dk = document.documentElement.classList.contains('dark');

    // Color tokens
    var bgOverlay = dk ? 'rgba(0,0,0,0.75)' : 'rgba(15,23,42,0.55)';
    var bgCard = dk ? '#1e293b' : '#ffffff';
    var bgHeader = dk ? 'rgba(255,255,255,0.04)' : 'rgba(248,250,252,1)';
    var bgInput = dk ? 'rgba(255,255,255,0.06)' : '#f1f5f9';
    var bgUpload = dk ? 'rgba(255,255,255,0.04)' : '#f8fafc';
    var bgUploadHv = dk ? 'rgba(99,102,241,0.12)' : '#eef2ff';
    var bgWarn = dk ? 'rgba(127,29,29,0.25)' : '#fff7ed';
    var bgTextarea = dk ? 'rgba(255,255,255,0.06)' : '#f8fafc';
    var borderCard = dk ? 'rgba(255,255,255,0.08)' : '#e2e8f0';
    var borderInput = dk ? 'rgba(255,255,255,0.10)' : '#e2e8f0';
    var borderDash = dk ? '#4f46e5' : '#818cf8';
    var borderWarn = dk ? '#92400e' : '#fed7aa';
    var colorAccent = '#6366f1'; // indigo
    var textMain = dk ? '#f1f5f9' : '#0f172a';
    var textSub = dk ? '#94a3b8' : '#64748b';
    var textLabel = dk ? '#cbd5e1' : '#475569';
    var textWarn = dk ? '#fb923c' : '#9a3412';
    var shadowCard = dk ? '0 32px 80px rgba(0,0,0,0.6)' : '0 24px 64px rgba(15,23,42,0.18)';

    // Info fields
    var info = infoData || {};
    var borrower = info.borrower || '-';
    var date = info.date || '-';
    var location = info.location || '-';
    var tools = Array.isArray(info.tools) ? info.tools : [];

    var toolsHtml = tools.length > 0
      ? tools.map(function (t) {
        return '<div style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid ' + borderInput + ';">' +
          '<span style="width:6px;height:6px;border-radius:50%;background:' + colorAccent + ';flex-shrink:0;"></span>' +
          '<div style="flex:1;">' +
          '<div style="font-size:0.8rem;font-weight:600;color:' + textMain + ';">' + (t.name || '-') + '</div>' +
          '<div style="font-size:0.7rem;color:' + textSub + ';">' + (t.code || '-') + '</div>' +
          '</div></div>';
      }).join('')
      : '<div style="font-size:0.8rem;color:' + textSub + ';">-</div>';

    var readonlyFieldStyle = 'width:100%;padding:7px 10px;border-radius:8px;border:1px solid ' + borderInput + ';background:' + bgInput + ';color:' + textMain + ';font-size:0.82rem;outline:none;box-sizing:border-box;';
    var labelStyle = 'display:block;font-size:0.72rem;font-weight:600;color:' + textLabel + ';margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em;';

    modal.style.background = bgOverlay;

    modal.innerHTML = `
    <style>
      @keyframes _hpmFadeIn { from { opacity:0; transform:scale(0.95); } to { opacity:1; transform:scale(1); } }
      @keyframes _hpmSlide  { from { opacity:0; transform:translateY(24px); } to { opacity:1; transform:translateY(0); } }
      #approve-tools { animation: _hpmSlide 0.22s cubic-bezier(.22,.68,0,1.2); }
      #_hpmUploadBox:hover { background:${bgUploadHv} !important; border-color:${colorAccent} !important; }
      #_hpmPhotoFile { display:none; }
      #_hpmBtnReject:hover  { filter:brightness(1.1); transform:translateY(-1px); }
      #_hpmBtnApprove:hover { filter:brightness(1.1); transform:translateY(-1px); }
      #_hpmBtnReject, #_hpmBtnApprove { transition: all 0.15s ease; }
      #_hpmTextarea:focus { border-color:${colorAccent} !important; box-shadow:0 0 0 3px rgba(99,102,241,0.15) !important; }
    </style>
    <div id="approve-tools" style="background:${bgCard};border-radius:20px;max-width:460px;width:100%;box-shadow:${shadowCard};border:1px solid ${borderCard};overflow:hidden;max-height:90vh;display:flex;flex-direction:column;">

      <!-- HEADER -->
      <div style="padding:18px 20px 14px;background:${bgHeader};border-bottom:1px solid ${borderCard};display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div>
          <div style="font-weight:700;font-size:1rem;color:${textMain};letter-spacing:-0.01em;">Verifikasi Alat</div>
          <div style="font-size:0.72rem;color:${textSub};margin-top:2px;">Tinjau dan setujui permintaan peminjaman</div>
        </div>
        <button onclick="_closeHeaderPhotoModal()" style="border:none;background:rgba(148,163,184,0.15);cursor:pointer;color:${textSub};width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:font-size:1rem;align-items:center;justify-content:center;font-size:0.9rem;transition:background 0.15s;"><i class="fas fa-times"></i></button>
      </div>

      <!-- SCROLLABLE BODY -->
      <div style="overflow-y:auto;flex:1;padding:18px 20px;display:flex;flex-direction:column;gap:14px;">

        <!-- INFO FIELDS -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label style="${labelStyle}">Nama Peminjam</label>
            <input type="text" readonly value="${borrower.replace(/"/g, '&quot;')}" style="${readonlyFieldStyle}" tabindex="-1">
          </div>
          <div>
            <label style="${labelStyle}">Tanggal</label>
            <input type="text" readonly value="${date.replace(/"/g, '&quot;')}" style="${readonlyFieldStyle}" tabindex="-1">
          </div>
        </div>
        <div>
          <label style="${labelStyle}">Lokasi</label>
          <input type="text" readonly value="${location.replace(/"/g, '&quot;')}" style="${readonlyFieldStyle}" tabindex="-1">
        </div>

        <!-- ALAT LIST -->
        <div>
          <label style="${labelStyle}">Alat</label>
          <div style="padding:8px 12px;border-radius:10px;border:1px solid ${borderInput};background:${bgInput};">
            ${toolsHtml}
          </div>
        </div>

        <!-- FOTO UPLOAD -->
        <div>
          <label style="${labelStyle}"><i class="fas fa-camera" style="margin-right:5px;color:${colorAccent};"></i>Foto Bukti Kondisi Alat</label>
          <div id="_hpmUploadBox" onclick="document.getElementById('_hpmPhotoFile').click()" style="border:2px dashed ${borderDash};border-radius:12px;padding:20px 14px;text-align:center;cursor:pointer;background:${bgUpload};transition:background 0.2s,border-color 0.2s;">
            <div id="_hpmPreview" style="display:none;margin-bottom:12px;"></div>
            <i id="_hpmUploadIcon" class="fas fa-cloud-upload-alt" style="font-size:1.8rem;color:${colorAccent};display:block;margin-bottom:6px;"></i>
            <div style="font-size:0.82rem;font-weight:600;color:${textMain};">Ketuk untuk upload foto</div>
            <div style="font-size:0.7rem;color:${textSub};margin-top:3px;">Foto kondisi alat sebelum diserahkan</div>
            <input type="file" id="_hpmPhotoFile" accept="image/*" capture="environment" onchange="_previewHeaderPhoto(this)">
          </div>
          <div style="margin-top:8px;padding:9px 12px;border-radius:10px;background:${bgWarn};border:1px solid ${borderWarn};font-size:0.72rem;color:${textWarn};display:flex;gap:8px;align-items:flex-start;">
            <i class="fas fa-exclamation-triangle" style="margin-top:1px;flex-shrink:0;"></i>
            <span><b>Penting:</b> Foto menjadi bukti kondisi alat saat diserahkan. Jika alat hilang/rusak, foto ini jadi pertanggungjawaban.</span>
          </div>
        </div>

        <!-- CATATAN -->
        <div>
          <label style="${labelStyle}">Catatan</label>
          <textarea id="_hpmTextarea" rows="3" placeholder="Tambahkan catatan (opsional)..." style="width:100%;padding:9px 12px;border-radius:10px;border:1px solid ${borderInput};background:${bgTextarea};color:${textMain};font-size:0.82rem;resize:vertical;font-family:inherit;outline:none;box-sizing:border-box;transition:border-color 0.2s,box-shadow 0.2s;"></textarea>
        </div>

      </div><!-- end scrollable body -->

      <!-- FOOTER BUTTONS -->
      <div style="padding:14px 20px;border-top:1px solid ${borderCard};display:flex;gap:10px;flex-shrink:0;background:${bgHeader};">
        <button id="_hpmBtnReject" onclick="_hpmDoReject()" style="flex:1;padding:10px;border-radius:10px;border:none;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;font-weight:700;font-size:0.85rem;cursor:pointer;box-shadow:0 4px 14px rgba(239,68,68,0.3);">
          <i class="fas fa-times-circle" style="margin-right:6px;"></i>Ditolak
        </button>
        <button id="headerPhotoSubmitBtn" onclick="_submitHeaderPhotoApprove()" style="flex:2;padding:10px;border-radius:10px;border:none;background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;font-weight:700;font-size:0.85rem;cursor:pointer;box-shadow:0 4px 14px rgba(34,197,94,0.3);">
          <i class="fas fa-check-circle" style="margin-right:6px;"></i>Di Terima
        </button>
      </div>

    </div>`;

    document.body.appendChild(modal);
    // Close on backdrop click
    modal.addEventListener('click', function (e) { if (e.target === modal) _closeHeaderPhotoModal(); });
  }

  function _previewHeaderPhoto(input) {
    var preview = document.getElementById('_hpmPreview');
    var icon = document.getElementById('_hpmUploadIcon');
    if (input.files && input.files[0]) {
      var reader = new FileReader();
      reader.onload = function (e) {
        if (preview) {
          preview.innerHTML = '<img src="' + e.target.result + '" style="max-height:150px;border-radius:10px;object-fit:cover;width:100%;box-shadow:0 4px 12px rgba(0,0,0,0.15);">';
          preview.style.display = 'block';
        }
        if (icon) icon.style.display = 'none';
      };
      reader.readAsDataURL(input.files[0]);
    }
  }

  function _hpmDoReject() {
    var btn = document.getElementById('_hpmBtnReject');
    var rejectBtn = btn;
    if (rejectBtn) { rejectBtn.disabled = true; rejectBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Memproses...'; }
    if (_headerPhotoBulkIds && Array.isArray(_headerPhotoBulkIds)) {
      var completed = 0; var failed = 0; var total = _headerPhotoBulkIds.length;
      _headerPhotoBulkIds.forEach(function (id) {
        var fd = new URLSearchParams();
        fd.append('id', id); fd.append('action', 'reject');
        fetch('./app/action/handle_loan_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() })
          .then(function (r) { return r.json(); }).then(function (d) { if (!d.success) failed++; }).catch(function () { failed++; })
          .finally(function () {
            completed++;
            if (completed >= total) {
              _closeHeaderPhotoModal();
              if (failed > 0) showHeaderToast(failed + ' gagal ditolak', 'error');
              else showHeaderToast(total + ' alat berhasil ditolak!', 'warning');
              setTimeout(function () { document.getElementById('notification-btn').click(); }, 300);
              if (typeof window.refreshAllToolsData === 'function') setTimeout(function () { window.refreshAllToolsData(); }, 400);
              if (window._globalSocket && window._globalSocket.connected) window._globalSocket.emit('broadcast_notification', { type: 'loan_action' });
            }
          });
      });
    } else {
      var fd = new URLSearchParams();
      fd.append('id', _headerPhotoPermitId); fd.append('action', 'reject');
      fetch('./app/action/handle_loan_action.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.success) {
            _closeHeaderPhotoModal();
            showHeaderToast('Permintaan berhasil ditolak', 'warning');
            setTimeout(function () { document.getElementById('notification-btn').click(); }, 300);
            if (typeof window.refreshAllToolsData === 'function') setTimeout(function () { window.refreshAllToolsData(); }, 400);
            if (window._globalSocket && window._globalSocket.connected) window._globalSocket.emit('broadcast_notification', { type: 'loan_action' });
          } else {
            showHeaderToast('Gagal: ' + (data.message || 'Error'), 'error');
            if (rejectBtn) { rejectBtn.disabled = false; rejectBtn.innerHTML = '<i class="fas fa-times-circle" style="margin-right:6px;"></i>Ditolak'; }
          }
        })
        .catch(function (err) {
          showHeaderToast('Error: ' + err.message, 'error');
          if (rejectBtn) { rejectBtn.disabled = false; rejectBtn.innerHTML = '<i class="fas fa-times-circle" style="margin-right:6px;"></i>Ditolak'; }
        });
    }
  }

  function _closeHeaderPhotoModal() {
    var modal = document.getElementById('headerPhotoModal');
    if (modal) { modal.style.opacity = '0'; modal.style.transition = 'opacity 0.15s ease'; setTimeout(function () { modal.remove(); }, 150); }
    _headerPhotoPermitId = null;
    _headerPhotoBulkIds = null;
  }

  function _submitHeaderPhotoApprove() {
    var fileInput = document.getElementById('_hpmPhotoFile');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
      showHeaderToast('Foto alat wajib diupload sebelum menyetujui!', 'error');
      return;
    }
    var btn = document.getElementById('headerPhotoSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i>Memproses...';

    _compressPhotoFile(fileInput.files[0]).then(function (compressedFile) {
      if (_headerPhotoBulkIds && Array.isArray(_headerPhotoBulkIds)) {
        var completed = 0;
        var total = _headerPhotoBulkIds.length;
        var failed = 0;
        _headerPhotoBulkIds.forEach(function (id) {
          var fd = new FormData();
          fd.append('id', id);
          fd.append('action', 'approve');
          fd.append('admin_photo', compressedFile);
          fetch('./app/action/handle_loan_action.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (!d.success) failed++; })
            .catch(function () { failed++; })
            .finally(function () {
              completed++;
              if (completed >= total) {
                _closeHeaderPhotoModal();
                if (failed > 0) { showHeaderToast(failed + ' gagal di-approve', 'error'); }
                else { showHeaderToast(total + ' alat berhasil disetujui!', 'success'); }
                setTimeout(function () { document.getElementById('notification-btn').click(); }, 300);
                if (typeof window.refreshAllToolsData === 'function') setTimeout(function () { window.refreshAllToolsData(); }, 400);
                if (window._globalSocket && window._globalSocket.connected) { window._globalSocket.emit('broadcast_notification', { type: 'loan_action' }); }
              }
            });
        });
      } else {
        var fd = new FormData();
        fd.append('id', _headerPhotoPermitId);
        fd.append('action', 'approve');
        fd.append('admin_photo', compressedFile);
        fetch('./app/action/handle_loan_action.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.success) {
              _closeHeaderPhotoModal();
              showHeaderToast('Permintaan berhasil disetujui!', 'success');
              setTimeout(function () { document.getElementById('notification-btn').click(); }, 300);
              if (typeof window.refreshAllToolsData === 'function') setTimeout(function () { window.refreshAllToolsData(); }, 400);
              if (window._globalSocket && window._globalSocket.connected) { window._globalSocket.emit('broadcast_notification', { type: 'loan_action' }); }
            } else {
              showHeaderToast('Gagal: ' + (data.message || 'Error'), 'error');
              btn.disabled = false;
              btn.innerHTML = '<i class="fas fa-check-circle" style="margin-right:6px;"></i>Di Terima';
            }
          })
          .catch(function (err) {
            showHeaderToast('Error: ' + err.message, 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle" style="margin-right:6px;"></i>Di Terima';
          });
      }
    });
  }

  function handleLeaveAction(id, action, source) {
    fetch('./app/action/handle_leave_request.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `id=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}&source=${encodeURIComponent(source || 'leave')}`
    })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          const dropdown = document.getElementById('notification-dropdown');
          dropdown.classList.add('hidden');
          setTimeout(() => {
            document.getElementById('notification-btn').click();
          }, 100);
          showHeaderToast('Permintaan berhasil ' + (action === 'approve' ? 'disetujui' : 'ditolak'), action === 'approve' ? 'success' : 'warning');

          if (window._globalSocket && window._globalSocket.connected) {
            window._globalSocket.emit('broadcast_notification', { type: 'leave_action' });
          }
        } else {
          showHeaderToast('Gagal memproses: ' + (data.message || ''), 'error');
        }
      })
      .catch(err => showHeaderToast('Error: ' + err.message, 'error'));
  }

  document.addEventListener('click', function (e) {
    const notifBtn = document.getElementById('notification-btn');
    const notifDropdown = document.getElementById('notification-dropdown');
    const userMenuToggle = document.getElementById('userMenuToggle');
    const userMenu = document.getElementById('userMenu');

    if (notifDropdown && !notifDropdown.classList.contains('hidden')) {
      if (!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
        notifDropdown.classList.add('hidden');
      }
    }

    if (userMenu && !userMenu.classList.contains('hidden')) {
      if (!userMenuToggle.contains(e.target) && !userMenu.contains(e.target)) {
        userMenu.classList.add('hidden');
      }
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    const notifDropdown = document.getElementById('notification-dropdown');
    if (notifDropdown) {
      notifDropdown.classList.add('hidden');
    }
    fetchAndRenderNotifications();

    const _waitForSocket = setInterval(function () {
      if (window._globalSocket) {
        clearInterval(_waitForSocket);
        window._globalSocket.on('notification_update', function (data) {
          console.log('[Header] Real-time notification update:', data.type || 'general');
          fetchAndRenderNotifications();
        });
      }
    }, 500);

    setInterval(function () {
      if (window._globalSocket && window._globalSocket.connected) return;
      fetchAndRenderNotifications();
    }, 30000);
  });

  (function () {
    const input = document.getElementById('globalSearchInput');
    const dropdown = document.getElementById('globalSearchResults');
    const wrap = document.getElementById('globalSearchWrap');
    if (!input || !dropdown) return;

    const SEARCH_USER_ROLE = "<?= htmlspecialchars($roleStr, ENT_QUOTES) ?>";

    const allPages = [
      { key: 'dashboard', icon: 'fa-home', label: 'Dashboard', desc: 'Main dashboard', roles: ['administrator', 'technician_manager', 'sales', 'technician', 'internship'] },
      { key: 'inbox', icon: 'fa-inbox', label: 'Inbox', desc: 'Notifications & approvals', roles: ['administrator', 'technician_manager', 'sales', 'technician'] },
      { key: 'profile', icon: 'fa-user', label: 'Profile', desc: 'Your profile', roles: ['administrator', 'technician_manager', 'sales', 'technician', 'internship'] },
      { key: 'settings', icon: 'fa-cog', label: 'Settings', desc: 'Account settings', roles: ['administrator', 'technician_manager', 'sales', 'technician', 'internship'] },
      { key: 'absence', icon: 'fa-user-check', label: 'Absence', desc: 'Attendance', roles: ['administrator', 'technician_manager', 'sales', 'technician', 'internship'] },
      { key: 'absen-list', icon: 'fa-list', label: 'List Absence', desc: 'Absence records', roles: ['administrator', 'technician_manager', 'sales', 'technician', 'internship'] },
      { key: 'report', icon: 'fa-chart-line', label: 'Report', desc: 'Work reports', roles: ['administrator', 'technician_manager', 'sales', 'technician'] },
      { key: 'tools', icon: 'fa-tools', label: 'Tools', desc: 'Tool management', roles: ['administrator', 'technician_manager', 'technician', 'sales'] },
      { key: 'tool-history', icon: 'fa-history', label: 'Tool History', desc: 'History of tools', roles: ['administrator', 'technician_manager', 'technician', 'sales'] },
      { key: 'check-monthly-tools', icon: 'fa-calendar-check', label: 'Monthly Check Tools', desc: 'Monthly tool check', roles: ['administrator'] },
      { key: 'chat', icon: 'fa-comments', label: 'Chat', desc: 'Team messaging', roles: ['administrator', 'technician_manager', 'sales', 'technician'] },
      { key: 'divisions', icon: 'fa-sitemap', label: 'Divisi', desc: 'Division management', roles: ['administrator', 'technician_manager', 'sales', 'technician'] },
      { key: 'request-material', icon: 'fa-box', label: 'Request Material', desc: 'Material requests', roles: ['administrator', 'technician'] },
      { key: 'bug-report', icon: 'fa-bug', label: 'Bug Report', desc: 'Report issues', roles: ['administrator', 'technician_manager', 'sales', 'technician', 'internship'] },
      { key: 'schedules', icon: 'fa-calendar-alt', label: 'Work Schedule', desc: 'Schedule management', roles: ['administrator', 'technician_manager', 'internship'] },
      { key: 'holidays', icon: 'fa-umbrella-beach', label: 'Holiday Management', desc: 'Holiday calendar', roles: ['administrator'] },
      { key: 'gps', icon: 'fa-map-marker-alt', label: 'GPS Management', desc: 'GPS location settings', roles: ['administrator'] },
      { key: 'account', icon: 'fa-users-cog', label: 'Account Handler', desc: 'Manage user accounts', roles: ['administrator'] }
    ];

    const pages = allPages.filter(p => p.roles.includes(SEARCH_USER_ROLE));

    let debounceTimer = null;
    let activeIdx = -1;
    let currentItems = [];
    let abortCtrl = null;

    function escHtml(s) {
      const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
    }

    function highlightMatch(text, q) {
      if (!q) return escHtml(text);
      const idx = text.toLowerCase().indexOf(q.toLowerCase());
      if (idx === -1) return escHtml(text);
      return escHtml(text.substring(0, idx))
        + '<mark style="background:#fde68a;color:#92400e;padding:0 1px;border-radius:2px;">'
        + escHtml(text.substring(idx, idx + q.length)) + '</mark>'
        + escHtml(text.substring(idx + q.length));
    }

    function show() { dropdown.style.display = 'block'; }
    function hide() { dropdown.style.display = 'none'; activeIdx = -1; }

    function navigate(url) {
      hide();
      input.value = '';
      input.blur();
      window.location.href = url;
    }

    function setActive(idx) {
      const items = dropdown.querySelectorAll('.gs-item');
      items.forEach(el => el.classList.remove('gs-active'));
      if (idx >= 0 && idx < items.length) {
        items[idx].classList.add('gs-active');
        items[idx].scrollIntoView({ block: 'nearest' });
      }
      activeIdx = idx;
    }

    function renderResults(q, pageResults, staffResults, toolResults) {
      let html = '';
      currentItems = [];

      if (pageResults.length) {
        html += '<div class="gs-group-title"><i class="fas fa-compass" style="margin-right:6px;"></i>Pages</div>';
        pageResults.forEach(p => {
          const url = '?page=' + p.key;
          currentItems.push(url);
          html += `<a href="${url}" class="gs-item" data-url="${url}">
          <div class="gs-icon gs-icon-page"><i class="fas ${p.icon}"></i></div>
          <div><div class="gs-name">${highlightMatch(p.label, q)}</div><div class="gs-sub">${escHtml(p.desc)}</div></div>
          <span class="gs-kbd">Enter</span>
        </a>`;
        });
      }

      if (staffResults.length) {
        html += '<div class="gs-group-title"><i class="fas fa-users" style="margin-right:6px;"></i>Staff</div>';
        staffResults.forEach(s => {
          const url = '?page=divisions';
          currentItems.push(url);
          const div = s.division_name ? ' · ' + escHtml(s.division_name) : '';
          html += `<a href="${url}" class="gs-item" data-url="${url}">
          <div class="gs-icon gs-icon-staff"><i class="fas fa-user"></i></div>
          <div><div class="gs-name">${highlightMatch(s.full_name, q)}</div><div class="gs-sub">@${escHtml(s.username)} · ${escHtml(s.role)}${div}</div></div>
        </a>`;
        });
      }

      if (toolResults.length) {
        html += '<div class="gs-group-title"><i class="fas fa-tools" style="margin-right:6px;"></i>Tools</div>';
        toolResults.forEach(t => {
          const url = '?page=tools';
          currentItems.push(url);
          const status = (t.current_status || 'Ready').toLowerCase();
          let badgeCls = 'gs-badge-ready';
          if (status === 'loan') badgeCls = 'gs-badge-loan';
          else if (status === 'handover') badgeCls = 'gs-badge-handover';
          html += `<a href="${url}" class="gs-item" data-url="${url}">
          <div class="gs-icon gs-icon-tool"><i class="fas fa-wrench"></i></div>
          <div><div class="gs-name">${highlightMatch(t.name, q)}</div><div class="gs-sub">${highlightMatch(t.code || '', q)}</div></div>
          <span class="gs-badge ${badgeCls}">${escHtml(t.current_status || 'Ready')}</span>
        </a>`;
        });
      }

      if (!html) {
        html = '<div class="gs-empty"><i class="fas fa-search" style="font-size:1.4rem;margin-bottom:6px;display:block;color:#cbd5e1;"></i>No results for "<strong>' + escHtml(q) + '</strong>"</div>';
      }

      dropdown.innerHTML = html;
      show();

      dropdown.querySelectorAll('.gs-item').forEach((item, i) => {
        item.addEventListener('click', function (e) {
          e.preventDefault();
          navigate(this.getAttribute('data-url'));
        });
        item.addEventListener('mouseenter', () => setActive(i));
      });
    }

    function doSearch(q) {
      if (!q) { hide(); return; }

      const qLower = q.toLowerCase();
      const pageHits = pages.filter(p =>
        p.label.toLowerCase().includes(qLower) ||
        p.key.toLowerCase().includes(qLower) ||
        p.desc.toLowerCase().includes(qLower)
      ).slice(0, 5);

      renderResults(q, pageHits, [], []);

      if (abortCtrl) abortCtrl.abort();
      abortCtrl = new AbortController();

      fetch('./app/action/global-search.php?q=' + encodeURIComponent(q), {
        signal: abortCtrl.signal
      })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            renderResults(q, pageHits, data.staff || [], data.tools || []);
          }
        })
        .catch(err => {
          if (err.name !== 'AbortError') console.warn('[Search]', err);
        });
    }

    input.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      const q = this.value.trim();
      if (!q) { hide(); return; }
      debounceTimer = setTimeout(() => doSearch(q), 200);
    });

    input.addEventListener('focus', function () {
      if (this.value.trim()) doSearch(this.value.trim());
    });

    input.addEventListener('keydown', function (e) {
      const items = dropdown.querySelectorAll('.gs-item');
      if (!items.length || dropdown.style.display === 'none') return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActive(activeIdx < items.length - 1 ? activeIdx + 1 : 0);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActive(activeIdx > 0 ? activeIdx - 1 : items.length - 1);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIdx >= 0 && activeIdx < currentItems.length) {
          navigate(currentItems[activeIdx]);
        }
      } else if (e.key === 'Escape') {
        hide();
        input.blur();
      }
    });

    document.addEventListener('click', function (e) {
      if (wrap && !wrap.contains(e.target)) hide();
    });

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey && e.key === 'k') || (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName))) {
        e.preventDefault();
        input.focus();
        input.select();
      }
    });
  })();
</script>