<?php

\defined('_JEXEC') or die;

// $html is complete, already escaped markup built by the UX Studio Form Builder renderer (see Renderer.php).
if (!empty($html)) {
    echo $html;
}
