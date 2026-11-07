<?php

\defined('_JEXEC') or die;

// $html is complete, already escaped markup built by the UX Studio Review Aggregator renderer.
if (!empty($html)) {
    echo $html;
}
