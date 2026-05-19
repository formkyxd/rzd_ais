<?php
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function getLocoStatusByMaintenanceType($code) {
    $code = strtoupper(str_replace(' ', '', $code));

    if (str_starts_with($code, 'ТО')) {
        return 'maintenance';
    }

    if (str_starts_with($code, 'ТР') || $code === 'КР' || $code === 'СР') {
        return 'repair';
    }

    return 'maintenance';
}

function statusBadge(string $status, array $labels, array $colors = []): string {
    $label = $labels[$status] ?? $status;
    $colorMap = [
        'new' => '#3B82F6', 'processing' => '#F59E0B', 'approved' => '#10B981',
        'rejected' => '#EF4444', 'completed' => '#6B7280', 'cancelled' => '#9CA3AF',
        'planned' => '#3B82F6', 'in_progress' => '#8B5CF6', 'delayed' => '#F97316',
        'operational' => '#10B981', 'maintenance' => '#F59E0B', 'repair' => '#EF4444',
        'decommissioned' => '#6B7280', 'draft' => '#9CA3AF', 'issued' => '#3B82F6',
        'paid' => '#10B981', 'overdue' => '#EF4444',
    ];
    $color = $colors[$status] ?? $colorMap[$status] ?? '#6B7280';
    return "<span class='badge' style='background:$color'>" . h($label) . "</span>";
}

function formatDate(?string $date): string {
    if (!$date) return '—';
    return date('d.m.Y', strtotime($date));
}

function formatDateTime(?string $dt): string {
    if (!$dt) return '—';
    return date('d.m.Y H:i', strtotime($dt));
}

function formatMoney(float $amount): string {
    return number_format($amount, 2, ',', ' ') . ' ₽';
}

function rolePersonName(?string $role): ?string {
    $map = [
        'manager'    => 'Калесников Артем Сергеевич',
        'dispatcher' => 'Шуляк Александр Сергеевич',
        'mechanic'   => 'Бильский Илья Олегович',
        'client'     => 'Мешкова Екатерина Игоревна',
    ];
    return $map[$role ?? ''] ?? null;
}

function displayPersonName(?string $name, ?string $role = null): string {
    $byRole = rolePersonName($role);
    if ($byRole) return $byRole;
    if (!$name || $name === '—') return '—';

    $legacyMap = [
        'иванов' => 'Калесников Артем Сергеевич',
        'петров' => 'Шуляк Александр Сергеевич',
        'сидоров' => 'Бильский Илья Олегович',
        'козлова' => 'Мешкова Екатерина Игоревна',
    ];
    $lower = mb_strtolower($name);
    foreach ($legacyMap as $needle => $realName) {
        if (mb_strpos($lower, $needle) !== false) return $realName;
    }
    return $name;
}

function formatWeight(?float $w): string {
    if ($w === null) return '—';
    return number_format($w, 1, ',', ' ') . ' т';
}

function isTem18(?string $series): bool {
    return (bool)preg_match('/ТЭМ\s*18|TEM\s*18/iu', $series ?? '');
}

function formatLocoUsage(?string $series, $value): string {
    $num = (int)$value;
    if (isTem18($series)) {
        return number_format($num, 0, ',', ' ') . ' сут.';
    }
    return number_format($num, 0, ',', ' ') . ' км';
}

function maintenanceCityOptions(): array {
    return ['Тюмень', 'Петрозаводск', 'Астрахань', 'Брянск', 'Екатеринбург'];
}

function priorityBadge(string $priority): string {
    $map = ['normal' => ['Обычный', '#6B7280'], 'high' => ['Высокий', '#F59E0B'], 'urgent' => ['Срочный', '#EF4444']];
    [$label, $color] = $map[$priority] ?? ['—', '#ccc'];
    return "<span class='badge' style='background:$color'>$label</span>";
}

function csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf() . '">';
}

function verifyCsrf(): bool {
    return isset($_POST['csrf_token']) && hash_equals(csrf(), $_POST['csrf_token']);
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function paginate(int $total, int $page, int $perPage = 20): array {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    return ['page' => $page, 'total_pages' => $totalPages, 'offset' => $offset, 'per_page' => $perPage, 'total' => $total];
}

function paginationLinks(array $p, string $url): string {
    if ($p['total_pages'] <= 1) return '';
    $html = '<div class="pagination">';
    for ($i = 1; $i <= $p['total_pages']; $i++) {
        $active = $i === $p['page'] ? ' active' : '';
        $sep = strpos($url, '?') !== false ? '&' : '?';
        $html .= "<a href='{$url}{$sep}page=$i' class='page-btn$active'>$i</a>";
    }
    $html .= '</div>';
    return $html;
}

function generateNumber(string $prefix): string {
    return $prefix . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function setFlash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
