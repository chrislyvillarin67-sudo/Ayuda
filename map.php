<?php
require_once __DIR__ . '/includes/functions.php';
// DEVIATION (per doc feedback: "District Admin sya lang mismo ang dapat nay
// map") -- the Map screen is District-Admin-only now.
requireStaff('district_admin');

// Never let the browser serve a stale/cached copy of the map page.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pageTitle = 'Map';
$activePage = 'map';
$isAdmin = true;
$barangayId = null;

$districts = $pdo->query("SELECT * FROM districts ORDER BY id")->fetchAll();
$selectedDistrict = (int)($_GET['district'] ?? 0);
$selectedBarangay = (int)($_GET['barangay'] ?? 0);

$where = '';
$params = [];
if ($selectedDistrict > 0) { $where = 'WHERE br.district_id = ?'; $params[] = $selectedDistrict; }
if ($selectedBarangay > 0) { $where = ($where ? $where . ' AND ' : 'WHERE ') . 'br.id = ?'; $params[] = $selectedBarangay; }
$stmt = $pdo->prepare("
    SELECT br.id, br.name, br.latitude, br.longitude, br.district_id, d.name AS district_name,
           (SELECT COUNT(*) FROM beneficiaries WHERE barangay_id = br.id) AS beneficiary_count,
           (SELECT COUNT(*) FROM beneficiaries WHERE barangay_id = br.id AND status='approved') AS approved_count,
           (SELECT COUNT(*) FROM distribution_logs WHERE barangay_id = br.id) AS distributed_count
    FROM barangays br JOIN districts d ON d.id = br.district_id
    $where
");
$stmt->execute($params);
$markers = $stmt->fetchAll();

$barangaysForFilter = $selectedDistrict ? getBarangays($pdo, $selectedDistrict) : [];

$totals = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM beneficiaries WHERE status='approved') AS total_approved,
      (SELECT COUNT(*) FROM distribution_logs) AS total_distributed
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Map - Ayuda Monitoring System</title>
<link rel="stylesheet" href="assets/css/style.css?v=20261019b">
<link rel="stylesheet" href="assets/leaflet/leaflet.min.css">
<script src="assets/leaflet/leaflet.min.js"></script>
<style>#leafletMap{height:70vh;width:100%;}
.leaflet-tooltip.brgy-label { background: rgba(255,255,255,.92); border: 1px solid #cfd8e3; color: #12304f; font-weight: 700; font-size: 11px; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
</style>
</head>
<body>
<div class="app-shell wide">
    <?php include __DIR__ . '/includes/app_header.php'; ?>
    <div class="content">
            <?php include __DIR__ . '/includes/flash.php'; ?>

            <div class="card" style="margin-bottom:12px;padding:12px;">
                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                        <div class="form-group" style="min-width:200px;margin-bottom:0;">
                            <label>Filter by District</label>
                            <select name="district" class="form-control" onchange="this.form.submit()">
                                <option value="0">All Districts</option>
                                <?php foreach ($districts as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $d['id']===$selectedDistrict?'selected':'' ?>><?= e($d['name']) ?> (District <?= (int)$d['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="min-width:200px;margin-bottom:0;">
                            <label>Filter by Barangay</label>
                            <select name="barangay" class="form-control" onchange="this.form.submit()">
                                <option value="0">All Barangays</option>
                                <?php foreach ($barangaysForFilter as $bb): ?>
                                    <option value="<?= $bb['id'] ?>" <?= $bb['id']===$selectedBarangay?'selected':'' ?>><?= e($bb['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                    <div class="small text-muted" style="margin-left:auto;">
                        <strong><?= (int)$totals['total_approved'] ?></strong> approved &bull;
                        <strong><?= (int)$totals['total_distributed'] ?></strong> distributed
                    </div>
                </div>
            </div>

            <div class="card" style="padding:0;overflow:hidden;">
                <div id="leafletMap"></div>
            </div>
    </div>
    <?php include __DIR__ . '/includes/app_bottomnav.php'; ?>
</div>
<script>
const markers = <?= json_encode($markers) ?>;
const districtName = <?= json_encode(array_column($districts, 'name', 'id')) ?>;
const districtColors = {
    '1': '#1e3a5f', '2': '#b87333', '3': '#8a1f1f', '4': '#6b7d30', '5': '#1f5e4d',
    '6': '#5a3a7a', '7': '#c5691f', '8': '#2f6d8a', '9': '#7a4a2f', '10': '#4a7a2f', '11': '#8a2f5e'
};
// ----- Leaflet + OpenStreetMap (keyless; works immediately) -----
// NOTE: CARTO's basemaps.cartocdn.com endpoints now answer HTTP 200 with a
// placeholder tile image that reads "API KEY REQUIRED". Never use them here.
if (typeof L === 'undefined') {
    document.getElementById('leafletMap').innerHTML = '<div class="card empty-state" style="margin-top:10vh;">The map library could not load. Please check your connection and reload this page.</div>';
} else {
    const mapEl = document.getElementById('leafletMap');
    const map = L.map('leafletMap').setView([7.1907, 125.4553], 11);

    // Tiles: OpenStreetMap first, automatic mirror fallback on errors.
    const OSM_ATTR = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors';
    let tilesLoaded = 0, tileErrors = 0, mirrorAdded = false;
    function addMirrorFallback() {
        if (mirrorAdded) return; mirrorAdded = true;
        L.tileLayer('https://tile.openstreetmap.de/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: OSM_ATTR
        }).addTo(map);
    }
    const baseTiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: OSM_ATTR
    });
    baseTiles.on('tileload', function () { tilesLoaded++; });
    baseTiles.on('tileerror', function () { tileErrors++; if (tileErrors >= 2) addMirrorFallback(); });
    baseTiles.addTo(map);

    // Barangay AREAS: colored circles + permanent name labels. These render
    // even if no basemap tiles load (offline), so the map is never blank.
    const AREA_RADIUS = 850;
    const shapeLayer = L.featureGroup().addTo(map);
    markers.forEach(function (m) {
        if (!m.latitude || !m.longitude) return;
        const color = districtColors[m.district_id] || '#1e3a5f';
        L.circle([parseFloat(m.latitude), parseFloat(m.longitude)], {
            radius: AREA_RADIUS,
            color: color, weight: 1.5, fillColor: color, fillOpacity: 0.18
        }).addTo(shapeLayer).bindTooltip('<strong>' + m.name + '</strong>', { permanent: true, direction: 'center', className: 'brgy-label' });
    });

    // Marker popups with stats.
    markers.forEach(function (m) {
        if (!m.latitude || !m.longitude) return;
        const pct = m.approved_count > 0 ? Math.round(m.distributed_count / m.approved_count * 100) : 0;
        const progress = pct >= 100 ? 'Complete' : (pct > 0 ? 'In Progress' : 'Pending');
        const progressColor = pct >= 100 ? '#1e8a3e' : (pct > 0 ? '#e0a100' : '#8b9aa8');
        L.marker([parseFloat(m.latitude), parseFloat(m.longitude)])
            .bindPopup(
                '<strong>' + m.name + '</strong><br>' + (m.district_name || '') + '<br><br>' +
                'Beneficiaries: <strong>' + m.beneficiary_count + '</strong><br>' +
                'Approved: <strong>' + m.approved_count + '</strong><br>' +
                'Distributed: <strong>' + m.distributed_count + '</strong> (' + pct + '%)<br>' +
                'Progress: <span style="color:' + progressColor + ';font-weight:700;">' + progress + '</span>'
            )
            .addTo(map);
    });

    if (shapeLayer.getLayers().length) {
        map.fitBounds(shapeLayer.getBounds().pad(0.12));
    }
    setTimeout(function () { map.invalidateSize(); }, 250);

    // Offline notice (no tiles arrived): shapes are still shown as vectors.
    const notice = document.createElement('div');
    notice.style.cssText = 'position:absolute;top:8px;left:8px;right:auto;z-index:1000;background:#fff7dd;border:1px solid #e0c56a;color:#6b5718;font-size:12px;border-radius:8px;padding:6px 10px;display:none;max-width:80%;box-shadow:0 2px 8px rgba(0,0,0,.12);';
    (mapEl.closest('.card') || mapEl).appendChild(notice);
    setTimeout(function () {
        if (tilesLoaded === 0) {
            notice.style.display = 'block';
            notice.innerHTML = 'Basemap unavailable (offline?). Showing barangay areas as colored shapes.';
        }
    }, 4500);
}
</script>
</body>
</html>