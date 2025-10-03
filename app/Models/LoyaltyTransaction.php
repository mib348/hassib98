<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LoyaltyTransaction Model
 *
 * Represents a transaction in the loyalty program (points earned, redeemed, or adjusted).
 * Provides audit trail for all loyalty point movements and helps track customer engagement.
 *
 * @property int $id
 * @property int $loyalty_member_id
 * @property string|null $shopify_order_id
 * @property string $type
 * @property int $points
 * @property string $description
 * @property array|null $metadata
 */
class LoyaltyTransaction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'loyalty_member_id',
        'shopify_order_id',
        'type',
        'points',
        'description',
        'metadata',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'points' => 'integer',
        'metadata' => 'array', // Stores additional transaction data as JSON
    ];

    /**
     * Get the loyalty member who owns this transaction.
     * This establishes the relationship back to the customer's loyalty account.
     */
    public function loyaltyMember(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMember::class);
    }

    /**
     * Scope to get only point earning transactions.
     * Useful for generating earning reports and statistics.
     */
    public function scopeEarned($query)
    {
        return $query->where('type', 'earned');
    }

    /**
     * Scope to get only point redemption transactions.
     * Helpful for tracking how customers use their loyalty benefits.
     */
    public function scopeRedeemed($query)
    {
        return $query->where('type', 'redeemed');
    }

    /**
     * Scope to get only manual adjustment transactions.
     * Used for admin-initiated point corrections or bonuses.
     */
    public function scopeAdjusted($query)
    {
        return $query->where('type', 'adjusted');
    }

    /**
     * Scope to get transactions for a specific order.
     * Useful for order-specific loyalty activity tracking.
     */
    public function scopeForOrder($query, string $orderId)
    {
        return $query->where('shopify_order_id', $orderId);
    }

    /**
     * Check if this transaction is a point earning transaction.
     *
     * @return bool True if points were earned, false otherwise
     */
    public function isEarning(): bool
    {
        return $this->type === 'earned' && $this->points > 0;
    }

    /**
     * Check if this transaction is a point redemption transaction.
     *
     * @return bool True if points were redeemed, false otherwise
     */
    public function isRedemption(): bool
    {
        return $this->type === 'redeemed' && $this->points < 0;
    }

    /**
     * Get a formatted description of the transaction for display.
     *
     * @return string Human-readable transaction description
     */
    public function getFormattedDescription(): string
    {
        $prefix = match($this->type) {
            'earned' => '✅ Earned',
            'redeemed' => '🎯 Redeemed',
            'adjusted' => '⚙️ Adjusted',
            default => 'Transaction'
        };

        return "{$prefix}: {$this->description}";
    }
}