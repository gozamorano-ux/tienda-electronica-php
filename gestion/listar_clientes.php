<?php
require "conexion.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Lista de Clientes</title>
  <link rel="stylesheet" href="estilo.css">
</head>
<body>
  <h2>Clientes registrados</h2>
<?php
$res = $conn->query("SELECT id_cliente, nombre, email, direccion FROM CLIENTE ORDER BY id_cliente ASC");
while($r = $res->fetch_assoc()){
    echo "{$r['id_cliente']} - {$r['nombre']} - {$r['email']} - {$r['direccion']}<br>";
}
?>
</body>
</html>
