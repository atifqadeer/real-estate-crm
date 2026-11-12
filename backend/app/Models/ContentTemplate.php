<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContentTemplate extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['organization_id', 'author_id', 'name', 'category', 'caption', 'hashtags', 'is_system'];
    protected function casts(): array { return ['is_system' => 'boolean']; }

    /** Starter categories from the product prompt (section 10). */
    public static function starterTemplates(): array
    {
        return [
            ['name' => 'Property for Sale', 'category' => 'Property for Sale', 'caption' => "🏡 For Sale in {location}\n{bedrooms} bed · {bathrooms} bath · {area}\nPrice: PKR {price}\nDM or WhatsApp us to book a viewing.", 'hashtags' => '#PropertyForSale #RealEstatePakistan'],
            ['name' => 'Property for Rent', 'category' => 'Property for Rent', 'caption' => "🔑 For Rent in {location}\n{bedrooms} bed · {bathrooms} bath · {area}\nMonthly rent: PKR {price}\nContact us today to schedule a visit.", 'hashtags' => '#PropertyForRent #RentInPakistan'],
            ['name' => 'New Property Listing', 'category' => 'New Property Listing', 'caption' => "✨ Just Listed — {location}\n{bedrooms} bed · {bathrooms} bath · {area}\nPKR {price}\nFull details on request.", 'hashtags' => '#NewListing #JustListed'],
            ['name' => 'Luxury Apartments', 'category' => 'Luxury Apartments', 'caption' => "🌟 Luxury Apartment Living in {location}\n{bedrooms} bed · {area}\nStarting from PKR {price}\nExperience elevated living — book your tour.", 'hashtags' => '#LuxuryApartments #PremiumLiving'],
            ['name' => 'Houses and Villas', 'category' => 'Houses and Villas', 'caption' => "🏠 {bedrooms} Bed House/Villa in {location}\n{area} · PKR {price}\nPeaceful street, prime location. Call now.", 'hashtags' => '#HouseForSale #VillaLiving'],
            ['name' => 'Commercial Property', 'category' => 'Commercial Property', 'caption' => "🏢 Commercial Opportunity in {location}\n{area} · PKR {price}\nIdeal for offices, retail and investment. Details on request.", 'hashtags' => '#CommercialProperty #Investment'],
            ['name' => 'Residential Projects', 'category' => 'Residential Projects', 'caption' => "🏗️ {project} — now booking in {location}\nEasy instalment plans available.\nMessage us for the payment plan and floor plans.", 'hashtags' => '#ResidentialProject #NewProject'],
            ['name' => 'Investment Opportunities', 'category' => 'Investment Opportunities', 'caption' => "📈 Investment Opportunity — {location}\nStarting from PKR {price}\nTalk to our team for complete, verified details before you decide.", 'hashtags' => '#RealEstateInvestment #PakistanProperty'],
            ['name' => 'Plot Sales', 'category' => 'Plot Sales', 'caption' => "📍 Plot for Sale in {location}\nSize: {area} · PKR {price}\nClear title, ready for transfer. Serious buyers only.", 'hashtags' => '#PlotForSale #LandForSale'],
            ['name' => 'Property Price Updates', 'category' => 'Property Price Updates', 'caption' => "💰 Price Update — {location}\nNow PKR {price} (was PKR {old_price})\nLimited time. Contact us to confirm availability.", 'hashtags' => '#PriceUpdate #PropertyDeal'],
            ['name' => 'Open House Announcements', 'category' => 'Open House Announcements', 'caption' => "🚪 Open House — {location}\n📅 {date} · 🕒 {time}\nWalk in and see it yourself. Everyone welcome!", 'hashtags' => '#OpenHouse #PropertyViewing'],
            ['name' => 'Property Viewing Invitations', 'category' => 'Property Viewing Invitations', 'caption' => "👀 You're invited to view this home in {location}\n{bedrooms} bed · {area} · PKR {price}\nReply to book your slot.", 'hashtags' => '#BookAViewing #HomeTour'],
            ['name' => 'Real Estate Tips', 'category' => 'Real Estate Tips', 'caption' => "💡 Real Estate Tip\n{tip}\nFollow us for a new tip every week.", 'hashtags' => '#RealEstateTips #PropertyAdvice'],
            ['name' => 'Customer Testimonials', 'category' => 'Customer Testimonials', 'caption' => "⭐ What our clients say\n“{quote}” — {client_name}\nThank you for trusting us with your property journey.", 'hashtags' => '#ClientLove #Testimonial'],
            ['name' => 'Project Launches', 'category' => 'Project Launches', 'caption' => "🚀 Launching Soon — {project}, {location}\nPre-launch booking opens {date}.\nRegister your interest today.", 'hashtags' => '#ProjectLaunch #ComingSoon'],
            ['name' => 'Promotional Campaigns', 'category' => 'Promotional Campaigns', 'caption' => "🎉 Special Offer\n{offer_details}\nValid till {date}. Terms apply. Message us to avail.", 'hashtags' => '#SpecialOffer #LimitedTime'],
        ];
    }
}
