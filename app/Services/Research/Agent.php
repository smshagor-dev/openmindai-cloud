<?php
namespace App\Services\Research;
interface Agent {public function definition(): array; public function execute(Context $ctx): array;}
