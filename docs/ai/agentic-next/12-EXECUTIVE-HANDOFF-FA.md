# 12 - تحويل اجرايي (فارسي)

**SHA نهايي مهندسي:** `092e9cc68435845f23b99a16a8fcea473e0c7514`  
**وضعيت توليد:** NOT_AUTHORIZED / NOT_DEPLOYED

## چه كاري اكنون واقعاً ممكن است

- گفتگوي grounded با ابزارهاي ERP خواندني از طريق Kernel و Copilot/Workspace
- بازيابي اسناد با ACL و citation از نوع DOCUMENT_EVIDENCE
- ابزارهاي توليد (BOM/دستور/آمادگي مواد) با UNVALUED و GL_BLOCKED صريح
- facade MCP (خاموش به صورت پيش‌فرض)
- كشف/صلاحيت مدل از Provider Gateway (نياز به credential مالك براي ابر)

## چه چيزي نياز به تاييد انساني دارد

هر mutation كسب‌وكار: proposal -> approve -> اجرا از دامنه كانونيك

## قبل از استقرار

1. سبز بودن Commercial MVP + RC1 MySQL/PHP روي SHA نهايي  
2. به‌روزرساني Worker + PHP  
3. فعال‌نکردن MCP تا تأييد امنيت عملياتي  
4. عدم دسترسي به production در اين كمپين  

كمپين پس از اين تحويل متوقف مي‌شود؛ كار غير بحراني فقط در backlog.
