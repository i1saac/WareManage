<?php
$conn = new mysqli('localhost', 'root', '', 'warehouse_db');
if ($conn->connect_error) { echo 'ERR:'.$conn->connect_error; exit; }
$res = $conn->query("SHOW CREATE TABLE client");
if (!$res) { echo 'ERR:'.$conn->error; exit; }
$row = $res->fetch_assoc();
echo $row['Create Table'];
?>
