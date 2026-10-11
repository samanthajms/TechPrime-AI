<?php
// Site root has no page of its own; send visitors to the login page.
header('Location: login.php');
exit;
