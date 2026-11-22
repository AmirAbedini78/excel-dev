# 12 - تحويل اجرايي (فارسي)

**SHA غيرقابل‌تغيير Agentic AI (tip):** `e9797662d85807a3a128d548e6557439800a689b` (PR #53)  
**Wave4 integrate (نيا):** `092e9cc68435845f23b99a16a8fcea473e0c7514`  
**بستهٔ انتشار مستقيم:** `14-DIRECT-PRODUCTION-RELEASE-PACKAGE.md`  
**وضعيت توليد:** بسته آمادهٔ Go/No-Go اپراتور — **NOT_DEPLOYED** (بدون mutation زنده توسط T0)

## چه كاري اكنون واقعاً ممكن است

- گفتگوي grounded با ابزارهاي ERP خواندني از طريق Kernel و Copilot/Workspace
- بازيابي اسناد با ACL و citation از نوع DOCUMENT_EVIDENCE
- ابزارهاي توليد (BOM/دستور/آمادگي مواد) با UNVALUED و GL_BLOCKED صريح
- facade MCP (**خاموش به‌صورت پيش‌فرض**؛ در اين بسته فعال نمي‌شود)
- كشف/صلاحيت مدل از Provider Gateway (نياز به credential مالك براي ابر)
- بستهٔ PHP + Worker با SHA-256 ثبت‌شده براي استقرار **كنترل‌شده** (نه auto `.cpanel.yml` كور)

## چه چيزي نياز به تاييد انساني دارد

هر mutation كسب‌وكار: proposal -> approve -> اجرا از دامنه كانونيك

## قبل از استقرار توليد (مالك / اپراتور)

1. تأييد tip = `e979766…` و مطابقت hash بسته‌ها  
2. سبز بودن Commercial MVP + RC1 MySQL/PHP روي همان tip  
3. sync كنترل‌شدهٔ PHP (**بدون** بازنويسي كور `storage` و `app/config.php`) + به‌روزرساني جداگانهٔ Worker  
4. rehearsal مهاجرت `AiSchema 1.2.0` روي كپي disposable از DB ناشناس‌شدهٔ واقعي  
5. MCP همچنان `0` / غايب — فعال‌سازي فقط با runbook جدا و مجوز جدا  
6. امضاي Go كتبي مالك + backup + rollback owner

## وضعيت

| مورد | وضعيت |
|------|--------|
| MCP | PATH_READY / DISABLED_BY_DEFAULT — در بستهٔ توليد **فعال نمي‌شود** |
| Staging | SKIPPED_BY_OWNER (پين RC1 `14fcf69` فقط آرشيو) |
| بستهٔ مستقيم توليد | PACKAGE_DOCUMENTED — به چک‌لیست Go/No-Go در doc 14 مراجعه شود |
| Production | NOT_DEPLOYED |

جزئيات فني، hashها، شكاف‌هاي `.cpanel.yml` و چک‌لیست اپراتور: `14-DIRECT-PRODUCTION-RELEASE-PACKAGE.md`.
