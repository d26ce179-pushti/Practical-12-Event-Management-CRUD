
<?php
session_start();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli("localhost", "root", "", "practical12");
$conn->set_charset("utf8mb4");

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$uploadDir = __DIR__ . "/uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$message = "";
$editEvent = [
    "id" => "", "event_name" => "", "event_date" => "",
    "category" => "", "description" => "",
    "status" => "Open", "poster" => ""
];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function uploadPoster($field, $uploadDir) {
    if (!isset($_FILES[$field]) ||
        $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Poster upload failed.");
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception("Poster must be 2 MB or smaller.");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime]) ||
        getimagesize($file['tmp_name']) === false) {
        throw new Exception("Only JPG, PNG, and WebP images are allowed.");
    }

    $filename = bin2hex(random_bytes(16)) . "." . $allowed[$mime];

    if (!move_uploaded_file(
        $file['tmp_name'],
        $uploadDir . $filename
    )) {
        throw new Exception("Could not save the uploaded poster.");
    }

    return $filename;
}

function removePoster($filename, $uploadDir) {
    if ($filename &&
        basename($filename) === $filename &&
        is_file($uploadDir . $filename)) {
        unlink($uploadDir . $filename);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals(
            $_SESSION['csrf'],
            $_POST['csrf'] ?? ''
        )) {
            throw new Exception("Invalid request token. Refresh the page.");
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['event_name'] ?? '');
            $date = $_POST['event_date'] ?? '';
            $category = trim($_POST['category'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'Open';

            if ($name === '' || $category === '' || $date === '') {
                throw new Exception("Event name, date and category are required.");
            }

            $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
                throw new Exception("Enter a valid event date.");
            }

            if (!in_array($status, ['Open', 'Closed'], true)) {
                throw new Exception("Invalid event status.");
            }

            if (strlen($name) > 150 || strlen($category) > 100) {
                throw new Exception("Event name or category is too long.");
            }

            $newPoster = uploadPoster('poster', $uploadDir);

            if ($id > 0) {
                $stmt = $conn->prepare("SELECT poster FROM events WHERE id=?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $old = $stmt->get_result()->fetch_assoc();

                if (!$old) {
                    if ($newPoster) removePoster($newPoster, $uploadDir);
                    throw new Exception("Event not found.");
                }

                $poster = $newPoster ?? $old['poster'];

                $stmt = $conn->prepare(
                    "UPDATE events SET event_name=?, event_date=?,
                     category=?, description=?, status=?, poster=?
                     WHERE id=?"
                );
                $stmt->bind_param(
                    "ssssssi",
                    $name, $date, $category, $description,
                    $status, $poster, $id
                );
                $stmt->execute();

                if ($newPoster) {
                    removePoster($old['poster'], $uploadDir);
                }

                $message = "Event updated successfully.";
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO events
                    (event_name, event_date, category, description, status, poster)
                    VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param(
                    "ssssss",
                    $name, $date, $category, $description,
                    $status, $newPoster
                );

                try {
                    $stmt->execute();
                } catch (Throwable $e) {
                    if ($newPoster) removePoster($newPoster, $uploadDir);
                    throw $e;
                }

                $message = "Event added successfully.";
            }
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $conn->prepare("SELECT poster FROM events WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $event = $stmt->get_result()->fetch_assoc();

            if (!$event) {
                throw new Exception("Event not found.");
            }

            $stmt = $conn->prepare("DELETE FROM events WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();

            removePoster($event['poster'], $uploadDir);
            $message = "Event deleted successfully.";
        }

        if ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM events WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            if (!$row) {
                throw new Exception("Event not found.");
            }

            $editEvent = $row;
        }
    } catch (Throwable $e) {
        $message = "Error: " . $e->getMessage();
    }
}

$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM events WHERE 1=1";
$types = "";
$params = [];

if ($search !== '') {
    $sql .= " AND (event_name LIKE ? OR description LIKE ?)";
    $term = "%" . $search . "%";
    $types .= "ss";
    $params[] = $term;
    $params[] = $term;
}

if ($categoryFilter !== '') {
    $sql .= " AND category=?";
    $types .= "s";
    $params[] = $categoryFilter;
}

if (in_array($statusFilter, ['Open', 'Closed'], true)) {
    $sql .= " AND status=?";
    $types .= "s";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY event_date DESC";
$stmt = $conn->prepare($sql);

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$categories = $conn->query(
    "SELECT DISTINCT category FROM events ORDER BY category"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Event Management System</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f9;margin:0;padding:20px;color:#222}
.container{max-width:1100px;margin:auto}
.card{background:white;padding:20px;margin-bottom:20px;border-radius:10px;box-shadow:0 2px 8px #ddd}
input,textarea,select{width:100%;padding:10px;margin:6px 0 14px;box-sizing:border-box}
button,.btn{padding:9px 14px;border:0;border-radius:5px;background:#1769aa;color:white;cursor:pointer;text-decoration:none}
.delete{background:#c62828}.message{padding:12px;background:#e8f5e9;margin-bottom:15px}
table{width:100%;border-collapse:collapse;background:white}
th,td{padding:10px;border:1px solid #ddd;text-align:left}
th{background:#eaf1f8}img.poster{max-width:100px;max-height:80px;object-fit:cover}
.filters{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:center}
@media(max-width:700px){.filters{grid-template-columns:1fr}table{font-size:13px}.card{overflow-x:auto}}
</style>
</head>
<body>
<div class="container">
<h1>Event Management System</h1>

<?php if ($message !== ''): ?>
<div class="message"><?= h($message) ?></div>
<?php endif; ?>

<div class="card">
<h2><?= $editEvent['id'] ? 'Update Event' : 'Add New Event' ?></h2>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?= h($editEvent['id']) ?>">

<label>Event Name</label>
<input name="event_name" maxlength="150" required value="<?= h($editEvent['event_name']) ?>">

<label>Event Date</label>
<input type="date" name="event_date" required value="<?= h($editEvent['event_date']) ?>">

<label>Category</label>
<input name="category" maxlength="100" required value="<?= h($editEvent['category']) ?>">

<label>Description</label>
<textarea name="description" rows="3"><?= h($editEvent['description']) ?></textarea>

<label>Status</label>
<select name="status">
<option value="Open" <?= $editEvent['status']==='Open'?'selected':'' ?>>Open</option>
<option value="Closed" <?= $editEvent['status']==='Closed'?'selected':'' ?>>Closed</option>
</select>

<label>Poster (JPG, PNG, WebP; maximum 2 MB)</label>
<input type="file" name="poster" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
<?php if ($editEvent['poster']): ?>
<p>Current poster:</p>
<img class="poster" src="<?= 'uploads/' . rawurlencode($editEvent['poster']) ?>" alt="Current event poster">
<?php endif; ?>

<button type="submit"><?= $editEvent['id'] ? 'Update Event' : 'Add Event' ?></button>
<a class="btn" href="index.php">Clear Form</a>
</form>
</div>

<div class="card">
<h2>Search and Filter Events</h2>
<form method="get" class="filters">
<input name="search" placeholder="Search event name or description" value="<?= h($search) ?>">
<select name="category">
<option value="">All Categories</option>
<?php foreach ($categories as $c): ?>
<option value="<?= h($c['category']) ?>" <?= $categoryFilter===$c['category']?'selected':'' ?>><?= h($c['category']) ?></option>
<?php endforeach; ?>
</select>
<select name="status">
<option value="">All Statuses</option>
<option value="Open" <?= $statusFilter==='Open'?'selected':'' ?>>Open</option>
<option value="Closed" <?= $statusFilter==='Closed'?'selected':'' ?>>Closed</option>
</select>
<button type="submit">Apply</button>
</form>
</div>

<div class="card">
<h2>Event Records</h2>
<table>
<thead>
<tr><th>ID</th><th>Event</th><th>Date</th><th>Category</th><th>Status</th><th>Poster</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($events as $event): ?>
<tr>
<td><?= h($event['id']) ?></td>
<td><?= h($event['event_name']) ?><br><?= h($event['description']) ?></td>
<td><?= h($event['event_date']) ?></td>
<td><?= h($event['category']) ?></td>
<td><?= h($event['status']) ?></td>
<td>
<?php if ($event['poster']): ?>
<img class="poster" src="<?= 'uploads/' . rawurlencode($event['poster']) ?>" alt="Event poster">
<?php else: ?>No poster<?php endif; ?>
</td>
<td>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="edit">
<input type="hidden" name="id" value="<?= h($event['id']) ?>">
<button type="submit">Edit</button>
</form>
<form method="post" style="display:inline" onsubmit="return confirm('Delete this event?')">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="id" value="<?= h($event['id']) ?>">
<button class="delete" type="submit">Delete</button>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php if (!$events): ?>
<tr><td colspan="7">No events found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</body>
</html>
