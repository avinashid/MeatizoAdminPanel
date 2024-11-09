<?php

namespace App\Models;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    //use HasFactory,SoftDeletes;
    use HasFactory;

    protected $fillable = [
        'id',
        'product_id',
        'type',
        'measurement',
        'price',
        'discounted_price',
        'stock',
        'stock_unit_id',
    ];
    public $timestamps = false;
    protected $hidden = ['deleted_at'];

    public static $statusAvailable = 1;
    public static $statusSoldOut = 0;


    public function images(){

        return $this->hasMany(ProductImages::class,'product_variant_id','id');
    }

    public function unit(){

        return $this->hasOne(Unit::class,'id','stock_unit_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');

    }
}
