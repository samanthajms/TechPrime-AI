<?php
session_start();
unset($_SESSION['paymongo_link_id'], $_SESSION['pending_order']);
header('Location: ../CLIENT/checkout.php?error=cancelled');
exit;