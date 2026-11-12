<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class Documentation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Documentation';

    protected static ?string $title = 'Documentation';

    protected static ?string $slug = 'documentation';

    protected static ?int $navigationSort = 50;

    protected ?string $subheading = 'Architecture, operational flow, safety rules, and user guide for the internal Print Hub team.';

    protected string $view = 'filament.pages.documentation';
}
