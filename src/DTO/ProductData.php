<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ProductData
{
    public function __construct(
        public ?string $brand,
        public ?string $model,
        public ?int $ram,
        public ?int $storage,
        public ?string $color,
        public ?string $imei = null,
        public ?string $originalName = null,
        public ?int $quantity = null,
        public ?string $sim = null,
    ) {}

    public function summary(): string
    {
        $title = trim(implode(' ', array_filter(
            [$this->brand, $this->model],
            static fn (?string $part): bool => $part !== null && $part !== '',
        )));

        $parts = [];

        if ($title !== '') {
            $parts[] = $title;
        }

        if ($this->ram !== null && $this->storage !== null) {
            $parts[] = $this->ram.'/'.$this->storage;
        } elseif ($this->storage !== null) {
            $parts[] = $this->storage.' GB';
        } elseif ($this->ram !== null) {
            $parts[] = $this->ram.' GB';
        }

        if ($this->color !== null && $this->color !== '') {
            $parts[] = $this->color;
        }

        if ($this->sim !== null && $this->sim !== '') {
            $parts[] = $this->sim;
        }

        $summary = trim(implode(' ', $parts));

        if ($summary !== '') {
            return $summary;
        }

        return trim((string) $this->originalName);
    }

    /**
     * @return array{
     *     brand: ?string,
     *     model: ?string,
     *     ram: ?int,
     *     storage: ?int,
     *     color: ?string,
     *     imei: ?string,
     *     original_name: ?string,
     *     quantity: ?int,
     *     sim: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'brand' => $this->brand,
            'model' => $this->model,
            'ram' => $this->ram,
            'storage' => $this->storage,
            'color' => $this->color,
            'imei' => $this->imei,
            'original_name' => $this->originalName,
            'quantity' => $this->quantity,
            'sim' => $this->sim,
        ];
    }
}
