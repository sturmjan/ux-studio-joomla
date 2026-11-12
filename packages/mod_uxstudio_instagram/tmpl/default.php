<?php

\defined('_JEXEC') or die;

// $html is complete, already escaped markup built by the UX1 Studio Instagram Feed renderer.
if (!empty($html)) {
    echo $html;
}
