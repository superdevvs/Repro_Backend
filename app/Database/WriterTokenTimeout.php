<?php

namespace App\Database;

/** Acquisition failed before business work; the caller's transaction was rolled back. */
final class WriterTokenTimeout extends \RuntimeException {}
