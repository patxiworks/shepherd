<?php

function flash(string $type, string $message): void
{
    admin_session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
