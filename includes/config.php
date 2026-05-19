<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'rzd_ais');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'АИС «Перевозки РЖД»');
define('APP_VERSION', '1.0.0');
define('APP_DEPOT', 'АИС «Перевозки РЖД»');
define('SESSION_LIFETIME', 3600 * 8);

define('BASE_URL', '/rzd_ais/');
function url($path = '') {
    return BASE_URL . ltrim($path, '/');
}

define('STATUS_LABELS', [
    'new'        => 'Новая',
    'processing' => 'В обработке',
    'approved'   => 'Согласована',
    'rejected'   => 'Отклонена',
    'completed'  => 'Выполнена',
    'cancelled'  => 'Отменена',
]);

define('STATUS_COLORS', [
    'new'        => '#3B82F6',
    'processing' => '#F59E0B',
    'approved'   => '#10B981',
    'rejected'   => '#EF4444',
    'completed'  => '#6B7280',
    'cancelled'  => '#9CA3AF',
]);

define('TRIP_STATUS_LABELS', [
    'planned'     => 'Запланирован',
    'in_progress' => 'В пути',
    'completed'   => 'Завершён',
    'cancelled'   => 'Отменён',
    'delayed'     => 'Задержка',
]);

define('LOCO_STATUS_LABELS', [
    'operational'    => 'Рабочий',
    'maintenance'    => 'ТО',
    'repair'         => 'Ремонт',
    'decommissioned' => 'Списан',
]);

define('INVOICE_STATUS_LABELS', [
    'draft'     => 'Черновик',
    'issued'    => 'Выставлен',
    'paid'      => 'Оплачен',
    'overdue'   => 'Просрочен',
    'cancelled' => 'Отменён',
]);

define('ROLE_ACCESS', [
    'admin'      => ['*'],
    'manager'    => ['reports'],
    'dispatcher' => ['dashboard', 'applications', 'trips', 'routes', 'clients.view', 'locomotives.view'],
    'mechanic'   => ['maintenance', 'locomotives', 'shift'],
    'client'     => ['dashboard', 'applications.own', 'trips.own'],
    'gov_rep'    => ['dashboard', 'reports.gov'],
]);