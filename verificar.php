
<?php
$controladores = PDO::getAvailableDrivers();

if (in_array("pgsql", $controladores)) {
    echo "<h2>¡PostgreSQL está habilitado en PHP!</h2>";
} else {
    echo "<h2>PostgreSQL todavía no está habilitado.</h2>";
}
?>
