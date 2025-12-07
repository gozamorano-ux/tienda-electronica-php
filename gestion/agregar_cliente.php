<?php
require "conexion.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Agregar Cliente</title>
  <link rel="stylesheet" href="estilo.css">
</head>
<body>
  <h2>Registrar Cliente</h2>
  <form method="POST">
    <label>Nombre:</label><input type="text" name="nombre" required><br>
    <label>Email:</label><input type="email" name="email" required><br>
    <label>Dirección:</label><input type="text" name="direccion" required><br>
    <button type="submit">Guardar</button>
  </form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST["nombre"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $direccion = trim($_POST["direccion"] ?? "");

    if ($nombre && filter_var($email, FILTER_VALIDATE_EMAIL) && $direccion) {
        $stmt = $conn->prepare("INSERT INTO CLIENTE(nombre,email,direccion) VALUES(?,?,?)");
        $stmt->bind_param("sss", $nombre, $email, $direccion);
        try {
            $stmt->execute();
            echo "<div style='color:green'>Cliente registrado correctamente.</div>";
        } catch (mysqli_sql_exception $e) {
            echo "<div style='color:red'>Error: " . $conn->error . "</div>";
        }
    } else {
        echo "<div style='color:red'>Datos inválidos.</div>";
    }
}
?>
</body>
</html>
