<?php
require "conexion.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Agregar Producto</title>
  <link rel="stylesheet" href="estilo.css">
</head>
<body>
  <h2>Registrar Producto</h2>
  <form method="POST">
    <label>Nombre:</label><input type="text" name="nombre" required><br>
    <label>Descripción:</label><textarea name="descripcion"></textarea><br>
    <label>Precio:</label><input type="number" name="precio" step="0.01" required><br>
    <label>Stock:</label><input type="number" name="stock" min="0" required><br>
    <button type="submit">Guardar</button>
  </form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST["nombre"] ?? "");
    $descripcion = trim($_POST["descripcion"] ?? "");
    $precio = $_POST["precio"] ?? null;
    $stock = $_POST["stock"] ?? null;

    if ($nombre && is_numeric($precio) && $precio > 0 && is_numeric($stock) && $stock >= 0) {
        $stmt = $conn->prepare("INSERT INTO PRODUCTO(nombre, descripcion, precio, stock) VALUES(?,?,?,?)");
        $stmt->bind_param("ssdi", $nombre, $descripcion, $precio, $stock);
        try {
            $stmt->execute();
            echo "<div style='color:green'>Producto guardado correctamente.</div>";
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
