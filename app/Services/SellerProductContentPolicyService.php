<?php

namespace App\Services;

use App\Models\Seller;
use App\Models\SellerProductContentViolation;

class SellerProductContentPolicyService
{
    /**
     * Returns stable category names only. Never return a matched value to the client.
     */
    public function inspect(array $fields): array
    {
        $text = $this->normalize($fields);
        if ($text === '') {
            return [];
        }

        $violations = [];
        if (preg_match('/(?<!\d)(?:\+?20\s*)?0?1[0125][\s\-\d]{7,}(?!\d)/u', $text)) {
            $violations[] = 'phone_number';
        }
        if (preg_match('/[\p{L}\p{N}.+_-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+/u', $text)) {
            $violations[] = 'email_address';
        }
        if (preg_match('/(?:https?:\/\/|www\.|t\.me\/|wa\.me\/|whatsapp\.com)/iu', $text)) {
            $violations[] = 'external_link';
        }
        if (preg_match('/\b(?:whatsapp|telegram|\x{0648}\x{0627}\x{062A}\x{0633}\x{0627}\x{0628}|\x{062A}\x{0644}\x{062C}\x{0631}\x{0627}\x{0645}|\x{062A}\x{0648}\x{0627}\x{0635}\x{0644}\s+\x{0645}\x{0639}\x{0646}\x{0627}|\x{0627}\x{062A}\x{0635}\x{0644}\s+\x{0628}\x{0646}\x{0627})\b/iu', $text)) {
            $violations[] = 'direct_contact';
        }

        $numberWord = '(?:zero|one|two|three|four|five|six|seven|eight|nine|\x{0635}\x{0641}\x{0631}|\x{0648}\x{0627}\x{062D}\x{062F}|\x{0625}?\x{062B}\x{0646}\x{0627}\x{0646}|\x{0627}\x{062B}\x{0646}\x{064A}\x{0646}|\x{062B}\x{0644}\x{0627}\x{062B}\x{0629}|\x{0623}?\x{0631}\x{0628}\x{0639}\x{0629}|\x{062E}\x{0645}\x{0633}\x{0629}|\x{0633}\x{062A}\x{0629}|\x{0633}\x{0628}\x{0639}\x{0629}|\x{062B}\x{0645}\x{0627}\x{0646}\x{064A}\x{0629}|\x{062A}\x{0633}\x{0639}\x{0629})';
        if (preg_match('/(?:' . $numberWord . ')(?:[\s\-]+(?:' . $numberWord . ')){2,}/iu', $text)) {
            $violations[] = 'written_phone_number';
        }

        return array_values(array_unique($violations));
    }

    public function record(Seller $seller, array $categories, ?int $productId = null): void
    {
        foreach ($categories as $category) {
            SellerProductContentViolation::create([
                'seller_id' => $seller->id,
                'product_id' => $productId,
                'category' => $category,
                'source' => 'seller_api',
                'detected_at' => now(),
            ]);
        }
    }

    /**
     * Counts distinct non-empty image references supplied by an API client.
     */
    public function countImages(mixed ...$sources): int
    {
        $names = [];
        foreach ($sources as $source) {
            if (is_string($source)) {
                $decoded = json_decode($source, true);
                $source = json_last_error() === JSON_ERROR_NONE ? $decoded : [$source];
            }

            foreach ((array) $source as $image) {
                $name = is_array($image) ? ($image['image_name'] ?? null) : $image;
                if (is_string($name) && trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
        }

        return count(array_unique($names));
    }

    private function normalize(array $fields): string
    {
        $values = [];
        array_walk_recursive($fields, function ($value) use (&$values) {
            if (is_string($value)) {
                $values[] = trim(strip_tags($value));
            }
        });

        return trim(implode("\n", $values));
    }
}
