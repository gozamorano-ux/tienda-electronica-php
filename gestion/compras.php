<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require "conexion.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Gestión de Compras</title>
  <link rel="stylesheet" href="estilo.css">
</head>
<body>
  <h2>Registrar Compra</h2>
  <form method="POST">
    <label>ID Cliente:</label>
    <input type="number" name="id_cliente" required><br>

    <label>ID Producto:</label>
    <input type="number" name="id_producto" required><br>

    <label>Cantidad:</label>
    <input type="number" name="cantidad" min="1" required><br>

    <label>Fecha:</label>
    <input type="datetime-local" name="fecha" required><br>

    <button type="submit">Registrar</button>
  </form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_cliente = (int)($_POST["id_cliente"] ?? 0);
    $id_producto = (int)($_POST["id_producto"] ?? 0);
    $cantidad = (int)($_POST["cantidad"] ?? 0);
    $fecha = $_POST["fecha"] ?? "";

    if ($id_cliente > 0 && $id_producto > 0 && $cantidad > 0 && $fecha) {
        $stmt = $conn->prepare("SELECT precio, stock FROM PRODUCTO WHERE id_producto=?");
        $stmt->bind_param("i", $id_producto);
        $stmt->execute();
        $stmt->bind_result($precio, $stock);
        $productoExiste = $stmt->fetch();
        $stmt->close();

        if ($productoExiste) {
            if ($stock >= $cantidad) {
                $total = $precio * $cantidad;
                $conn->begin_transaction();
                try {
                    $ins = $conn->prepare("INSERT INTO COMPRA(cantidad,total,fecha,id_producto,id_cliente) VALUES(?,?,?,?,?)");
                    $ins->bind_param("idsii", $cantidad, $total, $fecha, $id_producto, $id_cliente);
                    $ins->execute();
                    $ins->close();

                    $upd = $conn->prepare("UPDATE PRODUCTO SET stock = stock - ? WHERE id_producto = ?");
                    $upd->bind_param("ii", $cantidad, $id_producto);
                    $upd->execute();
                    $upd->close();

                    $conn->commit();
                    echo "<div style='color:green'>Compra registrada correctamente.</div>";
                } catch (Exception $e) {
                    $conn->rollback();
                    echo "<div style='color:red'>Error al registrar compra.</div>";
                }
            } else {
                echo "<div style='color:red'>Stock insuficiente.</div>";
            }
        } else {
            echo "<div style='color:red'>Producto no encontrado.</div>";
        }
    } else {
        echo "<div style='color:red'>Datos inválidos.</div>";
    }
}
?>

<h2>Compras registradas</h2>
<?php
$res = $conn->query("
SELECT CP.id_compra, CP.cantidad, CP.total, CP.fecha,
       P.nombre AS producto, C.nombre AS cliente
FROM COMPRA CP
JOIN PRODUCTO P ON CP.id_producto = P.id_producto
JOIN CLIENTE C ON CP.id_cliente = C.id_cliente
ORDER BY CP.fecha ASC
");
while($r = $res->fetch_assoc()){
    echo "#{$r['id_compra']} - {$r['cliente']} compró {$r['cantidad']} de {$r['producto']} por $".$r['total']." el {$r['fecha']}<br>";
}
?>

<h2>Clientes con más de 2 compras</h2>
<?php
$rep = $conn->query("
SELECT C.nombre, C.email, COUNT(*) AS compras
FROM COMPRA CP
JOIN CLIENTE C ON CP.id_cliente = C.id_cliente
GROUP BY CP.id_cliente
HAVING COUNT(*) > 2
");
while($r = $rep->fetch_assoc()){
    echo "{$r['nombre']} ({$r['email']}) - Compras: {$r['compras']}<br>";
}
?>
</body>
</html>
