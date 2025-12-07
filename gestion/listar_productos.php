<?php
require "conexion.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Lista de Productos</title>
  <link rel="stylesheet" href="estilo.css">
</head>
<body>
  <h2>Productos registrados</h2>
<?php
$res = $conn->query("SELECT id_producto, nombre, precio, stock FROM PRODUCTO ORDER BY id_producto ASC");
while($r = $res->fetch_assoc()){
    echo "{$r['id_producto']} - {$r['nombre']} - $".$r['precio']." - Stock: {$r['stock']}<br>";
}
?>
</body>
</html>
