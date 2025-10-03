<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LoyaltyMember Model
 *
 * Represents a customer enrolled in the loyalty program.
 * Manages customer loyalty points, tier levels, and purchase history for the buy-4-get-1-free program.
 *
 * @property int $id
 * @property string $shopify_customer_id
 * @property string $email
 * @property int $points_balance
 * @property int $lifetime_points
 * @property string $tier
 * @property int $items_purchased_count
 * @property int $free_items_pending
 * @property string $status
 */
class LoyaltyMember extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shopify_customer_id',
        'email',
        'points_balance',
        'lifetime_points',
        'tier',
        'items_purchased_count',
        'free_items_pending',
        'status',
    ];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'points_balance' => 'integer',
        'lifetime_points' => 'integer',
        'items_purchased_count' => 'integer',
        'free_items_pending' => 'integer',
    ];

    /**
     * Get all loyalty transactions for this member.
     * This relationship allows tracking of point earning and redemption history.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    /**
     * Calculate how many free items the customer is eligible for based on purchase count.
     *
     * @return int Number of free items available (1 free item per 4 purchases)
     */
    public function calculateFreeItemsAvailable(): int
    {
        return floor($this->items_purchased_count / 4);
    }

    /**
     * Calculate how many more items needed until next free item.
     *
     * @return int Number of items remaining until next free item
     */
    public function itemsUntilNextFreeItem(): int
    {
        $remainder = $this->items_purchased_count % 4;
        return $remainder === 0 ? 4 : 4 - $remainder;
    }

    /**
     * Update member tier based on lifetime points.
     * Bronze: 0-499 points, Silver: 500-999 points, Gold: 1000+ points
     */
    public function updateTier(): void
    {
        if ($this->lifetime_points >= 1000) {
            $this->tier = 'gold';
        } elseif ($this->lifetime_points >= 500) {
            $this->tier = 'silver';
        } else {
            $this->tier = 'bronze';
        }
    }

    /**
     * Award points to the member and update their tier.
     *
     * @param int $points Number of points to award
     * @param string $description Description of why points were awarded
     * @param string|null $orderId Associated Shopify order ID
     */
    public function awardPoints(int $points, string $description, string $orderId = null): void
    {
        $this->points_balance += $points;
        $this->lifetime_points += $points;
        $this->updateTier();
        $this->save();

        // Create transaction record
        $this->transactions()->create([
            'shopify_order_id' => $orderId,
            'type' => 'earned',
            'points' => $points,
            'description' => $description,
        ]);
    }

    /**
     * Add purchased items to the customer's count and update free items.
     *
     * @param int $itemCount Number of items purchased
     */
    public function addPurchasedItems(int $itemCount): void
    {
        $this->items_purchased_count += $itemCount;
        $this->free_items_pending = $this->calculateFreeItemsAvailable();
        $this->save();
    }
}