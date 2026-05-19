<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::requireLogin();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pageTitle = 'Маршруты перевозок';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!verifyCsrf()) {
        setFlash('error', 'CSRF error');
        redirect('/pages/routes.php');
    }

    if ($_POST['action'] === 'create') {

        $departure = trim($_POST['departure_station']);
        $arrival = trim($_POST['arrival_station']);

        $routeName = $departure . ' — ' . $arrival;

        $exists = Database::fetchOne(
            "SELECT id
             FROM routes
             WHERE LOWER(departure_station) = LOWER(?)
               AND LOWER(arrival_station) = LOWER(?)",
            [$departure, $arrival]
        );

        if ($exists) {
            setFlash('error', 'Такой маршрут уже существует');
            redirect('/pages/routes.php');
        }

        Database::insert(
            "INSERT INTO routes
            (
                name,
                departure_station,
                arrival_station,
                distance_km,
                estimated_duration_hours,
                route_type
            )
            VALUES (?,?,?,?,?,?)",
            [
                $routeName,
                $departure,
                $arrival,
                ($_POST['distance_km'] ?: null),
                ($_POST['estimated_duration_hours'] ?: null),
                'freight'
            ]
        );

        setFlash('success', 'Маршрут добавлен');
    }

    elseif ($_POST['action'] === 'toggle') {

        $rid = (int)$_POST['route_id'];

        Database::query(
            "UPDATE routes
             SET is_active = NOT is_active
             WHERE id=?",
            [$rid]
        );

        setFlash('info', 'Статус маршрута изменён');
    }

    redirect('/pages/routes.php');
}

$routes = Database::fetchAll(
    "SELECT r.*,
            (SELECT COUNT(*) FROM trips WHERE route_id = r.id) as trip_count,
            (SELECT COUNT(*) FROM applications WHERE route_id = r.id) as app_count
     FROM routes r
     WHERE r.route_type = 'freight'
        OR r.route_type IS NULL
     ORDER BY r.name"
);


require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex-between mb-2">

    <div style="color:var(--text-muted);font-size:13px">
        Всего маршрутов:
        <strong style="color:var(--text-primary)">
            <?= count($routes) ?>
        </strong>
    </div>

    <?php if (Auth::role() === 'admin' || Auth::role() === 'dispatcher'): ?>
        <button
            class="btn btn-primary"
            onclick="openModal('modal-route')"
        >
            + Добавить маршрут
        </button>
    <?php endif; ?>

</div>

<div class="card">

    <div class="table-wrap">

        <table>

            <thead>
                <tr>
                    <th>#</th>
                    <th>Название маршрута</th>
                    <th>Расстояние</th>
                    <th>Время в пути</th>
                    <th>Рейсов</th>
                    <th>Заявок</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($routes as $i => $r): ?>

                <tr>

                    <td
                        class="mono"
                        style="color:var(--text-muted)"
                    >
                        <?= $i + 1 ?>
                    </td>

                    <td style="font-weight:600">
                        <?= h($r['name']) ?>
                    </td>

                    <td class="mono">
                        <?= $r['distance_km']
                            ? number_format($r['distance_km'], 1, ',', ' ') . ' км'
                            : '—'
                        ?>
                    </td>

                    <td class="mono">
                        <?= $r['estimated_duration_hours']
                            ? $r['estimated_duration_hours'] . ' ч'
                            : '—'
                        ?>
                    </td>

                    <td
                        style="text-align:center"
                        class="mono"
                    >
                        <?= $r['trip_count'] ?>
                    </td>

                    <td
                        style="text-align:center"
                        class="mono"
                    >
                        <?= $r['app_count'] ?>
                    </td>

                </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</div>

<div
    class="modal-overlay"
    id="modal-route"
>

    <div class="modal">

        <div class="modal-header">

            <div class="modal-title">
                Добавить маршрут
            </div>

            <button
                class="modal-close"
                onclick="closeModal('modal-route')"
            >
                ×
            </button>

        </div>

        <div class="modal-body">

            <form method="POST">

                <?= csrfField() ?>

                <input
                    type="hidden"
                    name="action"
                    value="create"
                >

                <div class="form-grid">

                    <div class="form-group">

                        <label>
                            Станция отправления *
                        </label>

                        <input
                            type="text"
                            name="departure_station"
                            required
                            placeholder="Тюмень"
                        >

                    </div>

                    <div class="form-group">

                        <label>
                            Станция прибытия *
                        </label>

                        <input
                            type="text"
                            name="arrival_station"
                            required
                            placeholder="Екатеринбург"
                        >

                    </div>

                    <div class="form-group">

                        <label>
                            Расстояние, км
                        </label>

                        <input
                            type="number"
                            name="distance_km"
                            step="0.1"
                            min="0"
                            placeholder="480"
                        >

                    </div>

                    <div class="form-group">

                        <label>
                            Время в пути, часов
                        </label>

                        <input
                            type="number"
                            name="estimated_duration_hours"
                            step="0.5"
                            min="0"
                            placeholder="8.5"
                        >

                    </div>

                    <input
                        type="hidden"
                        name="route_type"
                        value="freight"
                    >

                </div>

                <div class="form-actions">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Добавить маршрут
                    </button>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="closeModal('modal-route')"
                    >
                        Отмена
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>