<?php

declare(strict_types=1);

/** Only pastries and drinks are sold in the tuckshop menu. */
function normalize_product_category(string $category): string
{
    $category = strtolower(trim($category));
    if ($category === 'drink' || $category === 'drinks') {
        return 'drink';
    }
    return 'pastry';
}

function is_valid_product_category(string $category): bool
{
    return in_array(normalize_product_category($category), ['pastry', 'drink'], true);
}

function default_icon_for_category(string $category): string
{
    return normalize_product_category($category) === 'drink' ? '🧃' : '🥧';
}

function group_inventory_by_category(array $rows): array
{
    $groups = ['pastry' => [], 'drink' => []];
    foreach ($rows as $row) {
        $cat = normalize_product_category((string) ($row['category'] ?? 'pastry'));
        $groups[$cat][] = $row;
    }
    return $groups;
}
