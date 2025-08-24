<?php
/*
 * PHPAiModel-NGram — reset.php
 * Clears the dialogue history stored in session.
 *
 * Developed by: Artur Strazewicz — concept, architecture, PHP N-gram runtime, UI.
 * Year: 2025. License: MIT.
 *
 * Links:
 *   GitHub:      https://github.com/iStark/PHPAiModel-NGram
 *   LinkedIn:    https://www.linkedin.com/in/arthur-stark/
 *   TruthSocial: https://truthsocial.com/@strazewicz
 *   X (Twitter): https://x.com/strazewicz
 */

declare(strict_types=1);
session_start();

// clear stored tokens (chat history)
unset($_SESSION['tokens']);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true]);
