<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_admin_audit_log',fn(Blueprint $t)=>$t->index(['created_at','id'],'audit_retention_order'));
        Schema::table('v2_mail_log',fn(Blueprint $t)=>$t->index(['created_at','id'],'mail_retention_order'));
        Schema::table('v2_usage_review',fn(Blueprint $t)=>$t->index(['reviewed_at','id'],'review_retention_order'));
    }
    public function down(): void
    {
        Schema::table('v2_admin_audit_log',fn(Blueprint $t)=>$t->dropIndex('audit_retention_order'));
        Schema::table('v2_mail_log',fn(Blueprint $t)=>$t->dropIndex('mail_retention_order'));
        Schema::table('v2_usage_review',fn(Blueprint $t)=>$t->dropIndex('review_retention_order'));
    }
};
