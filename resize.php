<?php
@session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_perfil'] !== 'admin') {
    header('Location: index.php');
    exit;
}
$origem = __DIR__ . '/assets/foto-miguel.jpg';
$destino = __DIR__ . '/imagens/foto-miguel.jpg';
$tamanho = 200;

$img = imagecreatefromjpeg($origem);
$w = imagesx($img);
$h = imagesy($img);
$novo = min($w, $h);
$q = min($w, $h);

$thumb = imagecreatetruecolor($tamanho, $tamanho);
imagecopyresampled($thumb, $img, 0, 0, ($w - $q) / 2, ($h - $q) / 2, $tamanho, $tamanho, $q, $q);
imagejpeg($thumb, $destino, 85);
imagedestroy($img);
imagedestroy($thumb);

echo "OK: " . round(filesize($destino) / 1024) . " KB\n";