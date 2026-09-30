<?php

namespace WCC\Diagnostic\Models;

interface DiagnosticInterface
{
    public function diagInterface(array $interface): Response;
    public function parseInterface($interface);
}
