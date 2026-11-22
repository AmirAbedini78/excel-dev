# 12 - تحويل اجرايي (فارسي)

**SHA نهايي مهندسي (Wave4):** `092e9cc68435845f23b99a16a8fcea473e0c7514`  
**Tip `origin/main` (پس از PR #52):** `a53019c6531e9be29274a6159b725737cde64547`  
**وضعيت توليد:** NOT_AUTHORIZED / NOT_DEPLOYED

## چه كاري اكنون واقعاً ممكن است

- گفتگوي grounded با ابزارهاي ERP خواندني از طريق Kernel و Copilot/Workspace
- بازيابي اسناد با ACL و citation از نوع DOCUMENT_EVIDENCE
- ابزارهاي توليد (BOM/دستور/آمادگي مواد) با UNVALUED و GL_BLOCKED صريح
- facade MCP (خاموش به صورت پيش‌فرض؛ مسير فعال‌سازي امن آماده — عضويت شركت سمت سرور)
- كشف/صلاحيت مدل از Provider Gateway (نياز به credential مالك براي ابر)
- بسته staging قابل اجرا در پين `14fcf69` + executor آفلاين (محلي PASS؛ remote در انتظار ميزبان)

## چه چيزي نياز به تاييد انساني دارد

هر mutation كسب‌وكار: proposal -> approve -> اجرا از دامنه كانونيك

## قبل از استقرار

1. سبز بودن Commercial MVP + RC1 MySQL/PHP روي SHA نهايي  
2. به‌روزرساني Worker + PHP  
3. فعال‌كردن MCP فقط با `mcp_enabled=1` روي ميزبان غيرتوليد پس از مرور امنيت (پيش‌فرض همچنان خاموش)  
4. عدم دسترسي به production در اين كمپين  
5. براي `STAGING_VERIFIED`: URL/DB/اسرار staging جدا + امضاي `RC1-STAGING-REMOTE-AUTH-CHECKLIST.md`

## وضعيت گام‌هاي بعد از پذيرش

| مورد | وضعيت |
|------|--------|
| MCP | PATH_READY / DISABLED_BY_DEFAULT (runbook اپراتور آماده؛ فلگ commit نشده) |
| Staging | READY / WAITING_FOR_STAGING_HOST (نه STAGING_VERIFIED) |
| Browser محلي | LOCAL_BROWSER_VERIFIED روي tip (نه staging) |
| Production | NOT_DEPLOYED |

كمپين پس از اين تحويل متوقف مي‌شود؛ كار غير بحراني فقط در backlog.
