<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addSellerActivationColumns();
        $this->addCustomerActivationColumns();
        $this->backfillExistingAccountActivationStatuses();
        $this->prepareSupportTickets();
        $this->prepareSellerActivationTickets();

        if (! Schema::hasTable('account_activation_cases')) {
            Schema::create('account_activation_cases', function (Blueprint $table) {
                $table->id();
                $table->string('subject_type', 20)->index();
                $table->unsignedBigInteger('subject_id')->index();
                $table->string('source_type', 30)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->unsignedBigInteger('assigned_admin_id')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->index();
                $table->text('decision_note')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('subject_hidden_at')->nullable();
                $table->timestamp('completed_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['subject_type', 'subject_id'], 'activation_case_subject_unique');
                $table->unique(['source_type', 'source_id'], 'activation_case_source_unique');
                $table->index(['status', 'assigned_admin_id'], 'activation_case_work_queue');
            });
        }

        if (! Schema::hasTable('account_activation_profile_fields')) {
            Schema::create('account_activation_profile_fields', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_activation_case_id')->index('activation_field_case_idx');
                $table->string('field_key', 80);
                $table->string('label', 150);
                $table->longText('value')->nullable();
                $table->boolean('is_sensitive')->default(true);
                $table->boolean('is_required')->default(false);
                $table->boolean('is_verified')->default(false);
                $table->unsignedBigInteger('created_by_admin_id')->index();
                $table->unsignedBigInteger('verified_by_admin_id')->nullable()->index();
                $table->timestamp('verified_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['account_activation_case_id', 'field_key'], 'activation_case_field_unique');
            });
        }

        if (! Schema::hasTable('account_activation_documents')) {
            Schema::create('account_activation_documents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_activation_case_id')->index('activation_document_case_idx');
                $table->string('document_type', 80)->index();
                $table->longText('document_number')->nullable();
                $table->string('original_name');
                $table->string('storage_disk', 40)->default('local');
                $table->string('storage_path');
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('sha256', 64)->nullable();
                $table->date('expires_at')->nullable();
                $table->boolean('is_required')->default(false);
                $table->string('verification_status', 30)->default('pending')->index();
                $table->unsignedBigInteger('uploaded_by_admin_id')->index();
                $table->unsignedBigInteger('verified_by_admin_id')->nullable()->index();
                $table->timestamp('verified_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('account_activation_case_events')) {
            Schema::create('account_activation_case_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_activation_case_id')->index('activation_event_case_idx');
                $table->string('event_type', 80)->index();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30)->nullable();
                $table->text('note')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['account_activation_case_id', 'created_at'], 'activation_case_timeline_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_activation_case_events');
        Schema::dropIfExists('account_activation_documents');
        Schema::dropIfExists('account_activation_profile_fields');
        Schema::dropIfExists('account_activation_cases');

        $this->dropColumnsIfPresent('support_tickets', [
            'purpose', 'review_status', 'reviewed_by_admin_id', 'reviewed_at',
            'closed_at', 'archived_at', 'hidden_from_subject_at',
        ]);
        $this->dropColumnsIfPresent('users', [
            'activation_status', 'activation_requested_at', 'activation_approved_at',
            'activation_approved_by_admin_id',
        ]);
        $this->dropColumnsIfPresent('sellers', [
            'activation_status', 'activation_requested_at', 'activation_approved_at',
            'activation_approved_by_admin_id',
        ]);
    }

    private function addSellerActivationColumns(): void
    {
        if (! Schema::hasTable('sellers')) {
            return;
        }

        Schema::table('sellers', function (Blueprint $table) {
            if (! Schema::hasColumn('sellers', 'activation_status')) {
                $table->string('activation_status', 30)->default('pending')->index();
            }
            if (! Schema::hasColumn('sellers', 'activation_requested_at')) {
                $table->timestamp('activation_requested_at')->nullable();
            }
            if (! Schema::hasColumn('sellers', 'activation_approved_at')) {
                $table->timestamp('activation_approved_at')->nullable();
            }
            if (! Schema::hasColumn('sellers', 'activation_approved_by_admin_id')) {
                $table->unsignedBigInteger('activation_approved_by_admin_id')->nullable()->index();
            }
        });
    }

    private function addCustomerActivationColumns(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'activation_status')) {
                $table->string('activation_status', 30)->default('pending')->index();
            }
            if (! Schema::hasColumn('users', 'activation_requested_at')) {
                $table->timestamp('activation_requested_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'activation_approved_at')) {
                $table->timestamp('activation_approved_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'activation_approved_by_admin_id')) {
                $table->unsignedBigInteger('activation_approved_by_admin_id')->nullable()->index();
            }
        });
    }

    private function prepareSupportTickets(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('support_tickets', 'purpose')) {
                $table->string('purpose', 60)->nullable()->index();
            }
            if (! Schema::hasColumn('support_tickets', 'review_status')) {
                $table->string('review_status', 30)->nullable()->index();
            }
            if (! Schema::hasColumn('support_tickets', 'reviewed_by_admin_id')) {
                $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->index();
            }
            if (! Schema::hasColumn('support_tickets', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable();
            }
            if (! Schema::hasColumn('support_tickets', 'closed_at')) {
                $table->timestamp('closed_at')->nullable();
            }
            if (! Schema::hasColumn('support_tickets', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index();
            }
            if (! Schema::hasColumn('support_tickets', 'hidden_from_subject_at')) {
                $table->timestamp('hidden_from_subject_at')->nullable()->index();
            }
        });
    }

    private function backfillExistingAccountActivationStatuses(): void
    {
        if (Schema::hasTable('sellers') && Schema::hasColumn('sellers', 'activation_status')) {
            DB::table('sellers')->where('status', 'approved')->update([
                'activation_status' => 'active',
                'activation_approved_at' => DB::raw('COALESCE(activation_approved_at, updated_at, created_at)'),
            ]);
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'activation_status')) {
            DB::table('users')->where('is_active', 1)->update([
                'activation_status' => 'active',
                'activation_approved_at' => DB::raw('COALESCE(activation_approved_at, updated_at, created_at)'),
            ]);
        }
    }

    private function prepareSellerActivationTickets(): void
    {
        if (! Schema::hasTable('seller_activation_tickets')) {
            Schema::create('seller_activation_tickets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->string('status', 30)->default('open')->index();
                $table->string('subject')->default('Seller account activation');
                $table->unsignedBigInteger('assigned_admin_id')->nullable()->index();
                $table->unsignedBigInteger('approved_by_admin_id')->nullable()->index();
                $table->text('decision_note')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('hidden_from_subject_at')->nullable()->index();
                $table->timestamp('completed_at')->nullable()->index();
                $table->timestamps();
            });
        } else {
            Schema::table('seller_activation_tickets', function (Blueprint $table) {
                if (! Schema::hasColumn('seller_activation_tickets', 'status')) {
                    $table->string('status', 30)->default('open')->index();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'subject')) {
                    $table->string('subject')->default('Seller account activation');
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'assigned_admin_id')) {
                    $table->unsignedBigInteger('assigned_admin_id')->nullable()->index();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'approved_by_admin_id')) {
                    $table->unsignedBigInteger('approved_by_admin_id')->nullable()->index();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'decision_note')) {
                    $table->text('decision_note')->nullable();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'opened_at')) {
                    $table->timestamp('opened_at')->nullable();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'closed_at')) {
                    $table->timestamp('closed_at')->nullable();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'hidden_from_subject_at')) {
                    $table->timestamp('hidden_from_subject_at')->nullable()->index();
                }
                if (! Schema::hasColumn('seller_activation_tickets', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->index();
                }
            });
        }

        if (! Schema::hasTable('seller_activation_ticket_messages')) {
            Schema::create('seller_activation_ticket_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_activation_ticket_id')->index('satm_ticket_id_idx');
                $table->string('sender_type', 20)->index();
                $table->unsignedBigInteger('sender_admin_id')->nullable()->index('satm_admin_id_idx');
                $table->text('body');
                $table->json('attachments')->nullable();
                $table->boolean('is_automatic')->default(false);
                $table->timestamps();
            });
        }
    }

    private function dropColumnsIfPresent(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $present = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($tableName, $column)));
        if ($present !== []) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($present));
        }
    }
};
