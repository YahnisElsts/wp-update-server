<?php
require __DIR__ . '/loader.php';
require __DIR__ . '/MyCustomServer.php';
$server = new MyCustomServer();
$server->handleRequest();