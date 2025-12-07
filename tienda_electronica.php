<?php
/************************
 * Tienda electrónica PHP 
 ************************/

/* ============================= */
/* Configuración de sesión segura */
/* ============================= */
$sessionLifetime = 3600;

session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path'     => '/',
    'domain'   => '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Strict'
]);
ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_secure', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? '1' : '0');
ini_set('session.cookie_httponly', '1');

session_start();

// Regeneración de ID de sesión en eventos críticos y cada cierto tiempo
if (!isset($_SESSION['__CREATED_AT'])) {
    $_SESSION['__CREATED_AT'] = time();
    session_regenerate_id(true);
} elseif (time() - $_SESSION['__CREATED_AT'] > 600) { // cada 10 min
    $_SESSION['__CREATED_AT'] = time();
    session_regenerate_id(true);
}

// Control de inactividad (auto-logout seguro)
$maxIdle = 1800; // 30 minutos
if (!isset($_SESSION['LAST_ACTIVITY'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
} elseif (time() - $_SESSION['LAST_ACTIVITY'] > $maxIdle) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['msg_err'] = "Tu sesión expiró por inactividad. Reanuda tu navegación.";
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

/* ============ */
/* Clase Pedido */
/* ============ */
class Pedido {
    public string $id;
    public string $descripcion;
    public string $tipo;
    public string $producto;
    public int $unidades;
    public ?string $observaciones;
    public string $fecha_creacion;
    public string $estado;

    public function __construct(array $data) {
        $this->id = $data['id'] ?? uniqid('pedido_', true);
        $this->descripcion = $data['descripcion'] ?? '';
        $this->tipo = $data['tipo'] ?? 'normal';
        $this->producto = $data['producto'] ?? '';
        $this->unidades = (int)($data['unidades'] ?? 1);
        $this->observaciones = $data['observaciones'] ?? null;
        $this->fecha_creacion = $data['fecha_creacion'] ?? date('c');
        $this->estado = $data['estado'] ?? 'pendiente';
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'descripcion' => $this->descripcion,
            'tipo' => $this->tipo,
            'producto' => $this->producto,
            'unidades' => $this->unidades,
            'observaciones' => $this->observaciones,
            'fecha_creacion' => $this->fecha_creacion,
            'estado' => $this->estado
        ];
    }

    public function save(string $filePath) {
        $dir = dirname($filePath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $items = [];
        if (file_exists($filePath)) {
            $items = json_decode(file_get_contents($filePath), true) ?: [];
        }
        $items[] = $this->toArray();

        $fp = fopen($filePath, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        if ($fp) fclose($fp);
    }

    public static function buscar(string $filePath, array $filtros = []): array {
        if (!file_exists($filePath)) return [];
        $items = json_decode(file_get_contents($filePath), true) ?: [];
        $result = array_filter($items, function($p) use ($filtros) {
            foreach ($filtros as $k => $v) {
                if ($v === null || $v === '') continue;
                if (!isset($p[$k])) return false;
                if (is_numeric($v)) {
                    if ($p[$k] != $v) return false;
                } else {
                    if (stripos((string)$p[$k], (string)$v) === false) return false;
                }
            }
            return true;
        });
        return array_map(fn($d) => new Pedido($d), array_values($result));
    }
}

/* ============================= */
/* Rutas de datos y productos */
/* ============================= */
$reviewsFile = __DIR__ . '/data/reviews.json';
$ordersFile  = __DIR__ . '/data/pedidos.json';

// Productos 
$productos = [
    ['id'=>'p1','nombre'=>'Auriculares X','precio'=>'29.990'],
    ['id'=>'p2','nombre'=>'Mouse Gamer Y','precio'=>'19.990'],
    ['id'=>'p3','nombre'=>'Teclado Mecánico Z','precio'=>'49.990'],
    ['id'=>'p4','nombre'=>'Webcam FullHD','precio'=>'39.990'],
];

/* ============================= */
/* Inicialización de carrito en sesión */
/* ============================= */
if (!isset($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

/* ============================= */
/* Funciones auxiliares de carrito */
/* ============================= */
function buscarProductoPorId(array $productos, string $id): ?array {
    foreach ($productos as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

function agregarAlCarrito(string $productId, int $cantidad, array $productos): bool {
    $p = buscarProductoPorId($productos, $productId);
    if (!$p || $cantidad < 1) return false;

    foreach ($_SESSION['carrito'] as &$item) {
        if ($item['id'] === $productId) {
            $item['cantidad'] += $cantidad;
            return true;
        }
    }
    $_SESSION['carrito'][] = [
        'id'       => $p['id'],
        'nombre'   => $p['nombre'],
        'precio'   => $p['precio'],
        'cantidad' => $cantidad
    ];
    return true;
}

function eliminarDelCarrito(string $productId): void {
    $_SESSION['carrito'] = array_values(array_filter($_SESSION['carrito'], fn($i) => $i['id'] !== $productId));
}

function actualizarCantidadCarrito(string $productId, int $cantidad): void {
    foreach ($_SESSION['carrito'] as &$item) {
        if ($item['id'] === $productId) {
            $item['cantidad'] = max(1, $cantidad);
            break;
        }
    }
}

function totalCarrito(): int {
    $total = 0;
    foreach ($_SESSION['carrito'] as $item) {
        $total += (int)str_replace('.', '', $item['precio']) * $item['cantidad'];
    }
    return $total;
}

/* ============================= */
/* Manejo de filtros de búsqueda */
/* ============================= */
$busqueda = trim(filter_input(INPUT_GET, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS)) ?: '';
$filtrados = array_filter($productos, function($p) use ($busqueda) {
    if ($busqueda === '') return true;
    return stripos($p['nombre'], $busqueda) !== false;
});

/* ============================= */
/* CSRF token para formularios */
/* ============================= */
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* ============================= */
/* Manejo de reseñas seguras */
/* ============================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!isset($_POST['csrf']) || !isset($_SESSION['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $_SESSION['msg_err'] = "Token inválido. Intenta nuevamente.";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#reviews');
        exit;
    }

    $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $rating     = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT, ["options"=>["min_range"=>1,"max_range"=>5]]);
    $author     = trim(filter_input(INPUT_POST, 'author', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $comment    = trim(filter_input(INPUT_POST, 'comment', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

    if ($product_id && buscarProductoPorId($productos, $product_id) && $rating && $author && $comment) {
        $review = [
            'product_id'=>$product_id,
            'rating'=>$rating,
            'author'=>$author,
            'comment'=>$comment,
            'created_at'=>date('c')
        ];
        if (!is_dir(__DIR__.'/data')) mkdir(__DIR__.'/data',0755,true);
        $reviews = file_exists($reviewsFile) ? json_decode(file_get_contents($reviewsFile), true) ?: [] : [];
        $reviews[] = $review;

        $fp = fopen($reviewsFile, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            ftruncate($fp,0); rewind($fp);
            fwrite($fp, json_encode($reviews, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            fflush($fp); flock($fp, LOCK_UN);
        }
        if ($fp) fclose($fp);

        $_SESSION['msg'] = "Reseña enviada. Gracias.";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#reviews');
        exit;
    } else {
        $_SESSION['msg_err'] = "Completa correctamente los campos de reseña.";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#reviews');
        exit;
    }
}

/* ============================= */
/* Manejo de pedidos seguros */
/* ============================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    if (!isset($_POST['csrf']) || !isset($_SESSION['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $_SESSION['msg_err'] = "Token inválido. Intenta nuevamente.";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#order_form');
        exit;
    }

    $descripcion   = filter_input(INPUT_POST, 'descripcion', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $tipo          = filter_input(INPUT_POST, 'tipo', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $producto      = filter_input(INPUT_POST, 'producto', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $unidades      = filter_input(INPUT_POST, 'unidades', FILTER_VALIDATE_INT, ["options"=>["min_range"=>1]]);
    $observaciones = trim(filter_input(INPUT_POST, 'observaciones', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

    if ($descripcion && $producto && $unidades !== false) {
        $pedido = new Pedido([
            'descripcion'=>$descripcion,
            'tipo'=>($tipo === 'express' ? 'express' : 'normal'),
            'producto'=>$producto,
            'unidades'=>$unidades,
            'observaciones'=>$observaciones
        ]);
        $pedido->save($ordersFile);
        $_SESSION['order_id'] = $pedido->id;
        session_regenerate_id(true);

        header('Location: ' . $_SERVER['PHP_SELF'] . '?confirmed=1#order_form');
        exit;
    } else {
        $_SESSION['msg_err'] = "Corrige los datos del pedido.";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#order_form');
        exit;
    }
}

/* ============================= */
/* Manejo de carrito (agregar, actualizar, eliminar, checkout) */
/* ============================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_action'])) {
    if (!isset($_POST['csrf']) || !isset($_SESSION['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        $_SESSION['msg_err'] = "Acción no autorizada (CSRF).";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#cart');
        exit;
    }

    $action = $_POST['cart_action'];

    if ($action === 'add') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $cantidad   = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT, ["options"=>["min_range"=>1]]);
        if ($product_id && $cantidad) {
            if (agregarAlCarrito($product_id, $cantidad, $productos)) {
                $_SESSION['msg'] = "Producto agregado al carrito.";
            } else {
                $_SESSION['msg_err'] = "No se pudo agregar el producto.";
            }
        } else {
            $_SESSION['msg_err'] = "Datos inválidos para agregar al carrito.";
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '#cart');
        exit;
    }

    if ($action === 'remove') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if ($product_id) {
            eliminarDelCarrito($product_id);
            $_SESSION['msg'] = "Producto eliminado del carrito.";
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '#cart');
        exit;
    }

    if ($action === 'update') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $cantidad   = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT, ["options"=>["min_range"=>1]]);
        if ($product_id && $cantidad) {
            actualizarCantidadCarrito($product_id, $cantidad);
            $_SESSION['msg'] = "Cantidad actualizada.";
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '#cart');
        exit;
    }

    if ($action === 'checkout') {
        
        session_regenerate_id(true);
        $_SESSION['carrito'] = [];
        $_SESSION['msg'] = "Pago procesado. Gracias por tu compra.";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#cart');
        exit;
    }
}

/* ============================= */
/* Cargar reseñas existentes */
/* ============================= */
$allReviews = file_exists($reviewsFile) ? json_decode(file_get_contents($reviewsFile), true) ?: [] : [];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Tienda - Electrónica PHP (Sesiones seguras + Carrito)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  
  <style>
    /* Paleta suave: azul/gris claro, tarjetas pastel */
    body{font-family:Arial,Helvetica,sans-serif;margin:20px;background:#eef3f8;color:#222}
    .card{background:#ffffff;padding:16px;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,0.08);margin-bottom:16px;border:1px solid #e5edf5}
    h1{color:#0f4c81}
    h2{color:#145b8a}
    label{display:block;margin-top:8px;font-weight:600;color:#244b66}
    input,select,textarea{width:100%;padding:10px;margin-top:4px;border:1px solid #c9d7e6;border-radius:6px;background:#f8fbff}
    input:focus,select:focus,textarea:focus{outline:none;border-color:#6aa6d8;box-shadow:0 0 0 3px rgba(106,166,216,0.2)}
    button{margin-top:10px;padding:10px 14px;border-radius:8px;border:0;background:#1e7cc7;color:white;cursor:pointer}
    button:hover{background:#165f99}
    .products{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
    .stars{color:#ff9900;font-weight:700}
    .review{border-top:1px dashed #e1e8f0;padding-top:8px;margin-top:8px}
    .messages{padding:10px;border-radius:8px;margin-bottom:12px}
    .ok{background:#e6ffed;border:1px solid #b6f2c4;color:#14532d}
    .err{background:#ffe6e6;border:1px solid #f2b6b6;color:#7a1f1f}
    .cart-item{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e8eff6;padding:10px 0}
    .cart-total{font-weight:bold;text-align:right;margin-top:10px;color:#0f4c81}
    .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .actions a{margin-right:8px;color:#165f99;text-decoration:none}
    .actions a:hover{text-decoration:underline}
  </style>
</head>
<body>
  <h1>Tienda electrónica online</h1>

  <?php if(isset($_SESSION['msg'])): ?>
    <div class="messages ok"><?= htmlspecialchars($_SESSION['msg']); unset($_SESSION['msg']); ?></div>
  <?php endif; ?>
  <?php if(isset($_SESSION['msg_err'])): ?>
    <div class="messages err"><?= htmlspecialchars($_SESSION['msg_err']); unset($_SESSION['msg_err']); ?></div>
  <?php endif; ?>

  <section class="card">
    <h2>Buscar productos</h2>
    <form method="get">
      <label for="q">Buscar por nombre</label>
      <input id="q" name="q" value="<?= htmlspecialchars($busqueda) ?>" placeholder="Ej: Auriculares, Teclado...">
      <button type="submit">Buscar</button>
    </form>
  </section>

  <section class="card">
    <h2>Productos</h2>
    <div class="products">
      <?php foreach($filtrados as $p): ?>
        <div class="card">
          <h3><?= htmlspecialchars($p['nombre']) ?></h3>
          <p style="color:#244b66">Precio: $<?= htmlspecialchars($p['precio']) ?></p>
          <div class="actions">
            <a href="#order_form" onclick="document.getElementById('producto').value='<?= htmlspecialchars($p['nombre']) ?>'">Registrar pedido</a>
            <a href="#reviews" onclick="document.getElementById('prod_for_review').value='<?= htmlspecialchars($p['id']) ?>'">Ver / dejar reseña</a>
          </div>
          <form method="post" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="cart_action" value="add">
            <input type="hidden" name="product_id" value="<?= htmlspecialchars($p['id']) ?>">
            <label for="cantidad_<?= htmlspecialchars($p['id']) ?>">Cantidad</label>
            <input id="cantidad_<?= htmlspecialchars($p['id']) ?>" name="cantidad" type="number" min="1" value="1" required>
            <button type="submit">Agregar al carrito</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="cart" class="card">
    <h2>Carrito de compras</h2>
    <?php if(empty($_SESSION['carrito'])): ?>
      <p>Tu carrito está vacío.</p>
    <?php else: ?>
      <?php foreach($_SESSION['carrito'] as $item): ?>
        <div class="cart-item">
          <div>
            <strong><?= htmlspecialchars($item['nombre']) ?></strong>
            <div style="color:#666">Precio: $<?= htmlspecialchars($item['precio']) ?> · ID: <?= htmlspecialchars($item['id']) ?></div>
          </div>
          <div>
            <form method="post" style="display:inline-block;margin-right:8px">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="cart_action" value="update">
              <input type="hidden" name="product_id" value="<?= htmlspecialchars($item['id']) ?>">
              <input name="cantidad" type="number" min="1" value="<?= htmlspecialchars($item['cantidad']) ?>" style="width:80px">
              <button type="submit">Actualizar</button>
            </form>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="cart_action" value="remove">
              <input type="hidden" name="product_id" value="<?= htmlspecialchars($item['id']) ?>">
              <button type="submit" style="background:#c62828">Eliminar</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="cart-total">Total: $<?= number_format(totalCarrito(), 0, ',', '.') ?></div>
      <form method="post" style="text-align:right;margin-top:12px">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="cart_action" value="checkout">
        <button type="submit" style="background:#178a00">Pagar</button>
      </form>
    <?php endif; ?>
  </section>

  <section id="order_form" class="card">
    <h2>Registrar pedido</h2>
    <form method="post" class="grid-2">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div>
        <label for="descripcion">Descripción del pedido</label>
        <input id="descripcion" name="descripcion" required>
      </div>

      <div>
        <label for="tipo">Tipo</label>
        <select id="tipo" name="tipo"><option value="normal">Normal</option><option value="express">Express</option></select>
      </div>

      <div>
        <label for="producto">Producto</label>
        <input id="producto" name="producto" required>
      </div>

      <div>
        <label for="unidades">Unidades</label>
        <input id="unidades" name="unidades" type="number" min="1" value="1" required>
      </div>

      <div style="grid-column:1/-1">
        <label for="observaciones">Observaciones</label>
        <textarea id="observaciones" name="observaciones"></textarea>
      </div>

      <div style="grid-column:1/-1">
        <button type="submit" name="submit_order">Enviar pedido</button>
      </div>
    </form>
    <?php if(isset($_GET['confirmed'])): ?>
      <div class="ok" style="margin-top:10px">Pedido registrado con ID: <?= htmlspecialchars($_SESSION['order_id'] ?? ''); ?></div>
    <?php endif; ?>
  </section>

  <section id="reviews" class="card">
    <h2>Reseñas</h2>
    <form method="post" class="review-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" id="prod_for_review" name="product_id" value="p1">
      <label for="rating">Calificación</label>
      <select id="rating" name="rating" required>
        <option value="">--</option>
        <option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option>
      </select>
      <label for="author">Tu nombre</label>
      <input id="author" name="author" required>
      <label for="comment">Reseña</label>
      <textarea id="comment" name="comment" required></textarea>
      <button type="submit" name="submit_review">Enviar reseña</button>
    </form>

    <div style="margin-top:16px">
      <?php
      foreach ($allReviews as $r) {
        $stars = str_repeat('★',(int)$r['rating']).str_repeat('☆',5-(int)$r['rating']);
        echo "<div class='review card'><div class='stars'>".$stars."</div>";
        echo "<div><strong>".htmlspecialchars($r['author'])."</strong> · <small>".htmlspecialchars($r['created_at'])."</small></div>";
        echo "<p>".nl2br(htmlspecialchars($r['comment']))."</p></div>";
      }
      ?>
    </div>
  </section>

  <footer style="margin-top:24px;font-size:0.9em;color:#5a6b7a">Web en PHP — Sesiones seguras y carrito persistente</footer>
</body>
</html>

<nav style="margin-top:16px">
  <a href="gestion/agregar_producto.php">Agregar Producto</a> |
  <a href="gestion/agregar_cliente.php">Agregar Cliente</a> |
  <a href="gestion/listar_productos.php">Ver Productos</a> |
  <a href="gestion/listar_clientes.php">Ver Clientes</a> |
  <a href="gestion/compras.php">Reporte de Compras</a>
</nav>
