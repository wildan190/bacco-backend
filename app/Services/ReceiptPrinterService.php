<?php

namespace App\Services;

use App\Models\Order;

class ReceiptPrinterService
{
    protected const LINE_WIDTH_58MM = 32;

    /**
     * Generate complete receipt payload for mini bluetooth thermal printer.
     *
     * @return array{
     *     order_id: int,
     *     invoice_number: string,
     *     store_name: string,
     *     store_address: string,
     *     sales_name: string,
     *     date: string,
     *     items: array<int, array{name: string, qty: int, price: float, subtotal: float}>,
     *     total_amount: float,
     *     cash_paid: float,
     *     change_due: float,
     *     plain_text_58mm: string,
     *     esc_pos_hex: string
     * }
     */
    public function formatReceipt(Order $order): array
    {
        $order->loadMissing(['store', 'user', 'items.product']);

        $storeName = $order->store?->name ?? 'BACCO SALES STORE';
        $storeAddress = $order->store?->address ?? '';
        $storePhone = $order->store?->phone ?? '';
        $salesName = $order->user?->name ?? 'Sales';
        $dateStr = $order->created_at ? $order->created_at->format('d/m/Y H:i') : date('d/m/Y H:i');

        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                'name' => $item->product?->name ?? 'Produk',
                'qty' => (int) $item->quantity,
                'price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
            ];
        }

        $plainText = $this->buildPlainText58mm(
            storeName: $storeName,
            storeAddress: $storeAddress,
            storePhone: $storePhone,
            invoice: $order->invoice_number,
            salesName: $salesName,
            dateStr: $dateStr,
            items: $items,
            total: (float) $order->total_amount,
            cash: (float) $order->cash_paid,
            change: (float) $order->change_due,
        );

        $escPosHex = $this->buildEscPosHex($plainText);

        return [
            'order_id' => $order->id,
            'invoice_number' => $order->invoice_number,
            'store_name' => $storeName,
            'store_address' => $storeAddress,
            'store_phone' => $storePhone,
            'sales_name' => $salesName,
            'date' => $dateStr,
            'items' => $items,
            'total_amount' => (float) $order->total_amount,
            'cash_paid' => (float) $order->cash_paid,
            'change_due' => (float) $order->change_due,
            'plain_text_58mm' => $plainText,
            'esc_pos_hex' => $escPosHex,
        ];
    }

    /**
     * Build 32-column monospaced receipt text for 58mm mini thermal printer.
     */
    protected function buildPlainText58mm(
        string $storeName,
        string $storeAddress,
        string $storePhone,
        string $invoice,
        string $salesName,
        string $dateStr,
        array $items,
        float $total,
        float $cash,
        float $change
    ): string {
        $w = self::LINE_WIDTH_58MM;
        $divider = str_repeat('-', $w);
        $doubleDivider = str_repeat('=', $w);

        $lines = [];
        // Header: Perusahaan Distributor Bacco (Bukan toko)
        $lines[] = $this->center('BACCO TOBACCO DISTRIBUSI', $w);
        $lines[] = $this->center('Sales Kanvas & Pasokan Toko', $w);
        $lines[] = $doubleDivider;

        // Meta Nota & Sales
        $lines[] = 'FAKTUR PENJUALAN KANVAS';
        $lines[] = 'No. Faktur: '.$invoice;
        $lines[] = 'Tanggal   : '.$dateStr;
        $lines[] = 'Salesman  : '.$salesName;
        $lines[] = $divider;

        // Informasi Toko Penerima / Pelanggan B2B
        $lines[] = 'PELANGGAN (TOKO TUJUAN):';
        $lines[] = 'Nama: '.substr($storeName, 0, $w - 6);
        if (! empty($storeAddress)) {
            $lines[] = 'Alm : '.substr($storeAddress, 0, $w - 6);
        }
        if (! empty($storePhone)) {
            $lines[] = 'Telp: '.$storePhone;
        }
        $lines[] = $divider;

        // Items Barang yang Dijual Sales ke Toko
        foreach ($items as $item) {
            $lines[] = substr($item['name'], 0, $w);
            $qtyPrice = $item['qty'].' x '.number_format($item['price'], 0, ',', '.');
            $subtotalStr = number_format($item['subtotal'], 0, ',', '.');
            $lines[] = $this->rowBetween($qtyPrice, $subtotalStr, $w);
        }

        $lines[] = $divider;

        // Totals
        $lines[] = $this->rowBetween('TOTAL TAGIHAN', 'Rp '.number_format($total, 0, ',', '.'), $w);
        $lines[] = $this->rowBetween('TUNAI DITERIMA', 'Rp '.number_format($cash, 0, ',', '.'), $w);
        $lines[] = $this->rowBetween('KEMBALIAN', 'Rp '.number_format($change, 0, ',', '.'), $w);
        $lines[] = $doubleDivider;

        // Tanda Terima Toko Penerima
        $lines[] = $this->center('Tanda Terima Toko:', $w);
        $lines[] = "\n";
        $lines[] = $this->center('(....................)', $w);
        $lines[] = $this->center('Barang diterima lengkap & baik.', $w);
        $lines[] = $this->center('Terima kasih atas kemitraan Anda', $w);
        $lines[] = "\n\n";

        return implode("\n", $lines);
    }

    protected function center(string $text, int $width): string
    {
        $text = trim($text);
        $len = strlen($text);
        if ($len >= $width) {
            return substr($text, 0, $width);
        }
        $left = (int) floor(($width - $len) / 2);

        return str_repeat(' ', $left).$text;
    }

    protected function rowBetween(string $left, string $right, int $width): string
    {
        $leftLen = strlen($left);
        $rightLen = strlen($right);
        $space = $width - ($leftLen + $rightLen);
        if ($space < 1) {
            $space = 1;
        }

        return $left.str_repeat(' ', $space).$right;
    }

    /**
     * Convert text to basic ESC/POS binary hex stream.
     */
    protected function buildEscPosHex(string $text): string
    {
        // ESC @ (initialize)
        $binary = "\x1B\x40";
        // ASCII content
        $binary .= $text;
        // Feed 3 lines and cut paper ESC m or GS V 65 0
        $binary .= "\n\n\n\x1D\x56\x41\x03";

        return bin2hex($binary);
    }
}
