from __future__ import annotations
import json
import sys
import threading
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
ENGINE=Path('/app') if Path('/app/business_skills.py').is_file() else ROOT/'engine'
sys.path.insert(0,str(ENGINE))
import business_skills as BS

def env(entities,company_id=1):
    return {"context":{"context_envelope":{
        "version":"v2","validated":True,"company_id":company_id,
        "attached_entities":entities,
        "current_page":{"entities":[]},
    }}}

class Dummy:
    def __init__(self):
        self.calls=[];self.traces=[];self.progress_lock=threading.Lock();self.current_trace=[]
    def trace(self,job,stage,message,details=None):
        self.traces.append((stage,message,details or {}))
    def model_for(self,role): return "qwen3.5:0.8b"
    def ollama_chat(self,*args,**kwargs):
        return {"message":{"content":json.dumps({"capabilities":["trade-risk"]})}}
    def tool(self,job,name,args,call_id):
        self.calls.append((name,args))
        if name=="crm_customer_360":
            pid=int(args["party_id"])
            return {"party":{"name":f"مشتری {pid}","code":f"CUS-{pid}"},
                    "financial":{"current_balance_irr":100*pid,"balance_nature":"بدهکار","recorded_sales_net_irr":1000*pid,"sales_document_count":pid,"outstanding_sales_quantity":2*pid},
                    "crm":{"open_pipeline_irr":500*pid,"weighted_pipeline_irr":250*pid,"next_followup":None}}
        if name=="document_analytics":
            pid=int(args["party_id"])
            return {"summary":{"document_count":2*pid,"net_total":10000*pid},
                    "groups":[{"label":"1405/04","net_total":8000*pid},{"label":"1405/05","net_total":10000*pid}]}
        if name=="party_ledger": return {"balance":3000*int(args["party_id"]),"rows":[]}
        if name=="search_trade_cases":
            return {"rows":[{"id":1,"case_no":"TRD-1"},{"id":2,"case_no":"TRD-2"}]}
        if name=="trade_risk_summary":
            return {"rows":[{"case_no":"TRD-1","risk_level":"high","delay_days":4},{"case_no":"TRD-2","risk_level":"medium","delay_days":2}]}
        if name=="trade_case_snapshot":
            return {"case":{"case_no":"TRD-1","supplier_name":"تامین تست","incoterm":"FOB","status":"in_transit","clearance_status":"hold"},
                    "shipments":[{"mode":"sea","status":"in_transit","eta":"1405/06/25"}]}
        if name=="landed_cost_summary":
            return {"purchase_base_irr":500000000,"estimated_additional_irr":120000000,"actual_additional_recorded_irr":100000000,"projected_landed_total_irr":600000000}
        if name=="replenishment_risk":
            return {"rows":[{"code":"TEST","name":"TEST","available":"1","expected_inbound":"2","min_stock":"5","suggested_replenishment":"2"}]}
        if name=="trade_manager_brief":
            return {"trade":{"risk_count":1,"rows":[{"case_no":"TRD-1","risk_level":"high","clearance_status":"hold","projected_landed_total_irr":620000000}]},
                    "inventory":{"shortage_count":1,"rows":[]},"sales":{"at_risk_count":0,"at_risk":[]}}
        raise AssertionError(name)

class Cycle12SkillsCapabilityNlp(unittest.TestCase):
    def test_catalog_has_roadmap_skills(self):
        for cid in ["customer-review","compare-customers","supplier-review","compare-suppliers","trade-risk","inventory-risk","executive-brief"]:
            self.assertIn(cid,BS.CAPABILITIES)

    def test_slash_explicit_skill(self):
        self.assertEqual(BS.explicit_skill("/supplier-review @تامین"),"supplier-review")
        self.assertEqual(BS.explicit_skill("لطفا /trade-risk بررسی کن"),"trade-risk")

    def test_natural_customer_compare_variants(self):
        entities=[{"type":"party.customer","id":1},{"type":"party.customer","id":2}]
        for prompt in ["این دوتا مشتری رو مقایسه کن","کدوم مشتری برای پیگیری فروش اولویت بیشتری داره؟","بین این دو مشتری کدام وضعیت بهتری دارد؟"]:
            self.assertIn("compare-customers",BS.lexical_retrieve(prompt,entities))

    def test_natural_supplier_compare_variants(self):
        entities=[{"type":"party.supplier","id":1},{"type":"party.supplier","id":2}]
        for prompt in ["این دوتا تامین کننده رو مقایسه کن","کدوم تأمین‌کننده بهتر بوده؟","بین این دو تامین کننده تاخیر کدوم کمتر بوده؟"]:
            self.assertIn("compare-suppliers",BS.lexical_retrieve(prompt,entities))

    def test_natural_trade_risk_variants(self):
        entities=[{"type":"trade.case","id":9}]
        for prompt in ["اگه این بار دیر برسه چی میشه؟","ریسک این پرونده بازرگانی چیه؟","وضعیت ETA و گمرک این محموله نگران کننده هست؟"]:
            self.assertIn("trade-risk",BS.lexical_retrieve(prompt,entities))

    def test_model_retriever_is_id_only(self):
        w=Dummy();job={"id":1,"company_id":1,**env([],1)}
        prompt="یه نگاه کلی بنداز ببین چه چیزی اینجا خطرناکه"
        self.assertEqual(BS.lexical_retrieve(prompt,[]),[])
        selected,source=BS.retrieve(w,job,prompt)
        self.assertEqual(selected,["trade-risk"]);self.assertEqual(source,"small_model_capability_id")

    def test_write_prompt_bypasses_capability_filter(self):
        class W: pass
        def original(self,j,t): return "ok",{"mode":"guarded_test","tools_used":[]}
        W.process_agent=original
        BS.install_business_skills(W)
        w=W();w.trace=lambda *a,**k:None
        tools=[{"name":"create_trade_case"},{"name":"trade_risk_summary"}]
        text,meta=w.process_agent({"id":1,"company_id":1,"prompt":"یک پرونده بازرگانی ایجاد کن"},tools)
        self.assertEqual(text,"ok")

    def test_customer_compare_is_grounded(self):
        w=Dummy();job={"id":5,"company_id":1,**env([
            {"type":"party.customer","id":1,"label":"الف"},
            {"type":"party.customer","id":2,"label":"ب"},
        ],1)}
        text,meta=BS.compare_customers(w,job,BS.context_entities(job))
        self.assertEqual(meta["mode"],"crm_customer_compare_read")
        self.assertEqual([c[0] for c in w.calls],["crm_customer_360","crm_customer_360"])
        self.assertIn("مقایسه مشتری‌ها",text);self.assertIn("هیچ عددی توسط مدل ساخته نشده",text)

    def test_supplier_review_combines_procurement_finance_trade(self):
        w=Dummy();job={"id":6,"company_id":1,**env([{"type":"party.supplier","id":2,"label":"تامین تست"}],1)}
        text,meta=BS.supplier_review(w,job,BS.context_entities(job))
        self.assertEqual(meta["mode"],"procurement_supplier_review")
        self.assertEqual([x[0] for x in w.calls],["document_analytics","party_ledger","search_trade_cases","trade_risk_summary"])
        self.assertIn("ریسک پرونده‌ها",text);self.assertIn("خرید قطعی ۶ ماه اخیر",text)

    def test_supplier_compare_is_read_only(self):
        w=Dummy();job={"id":7,"company_id":1,**env([
            {"type":"party.supplier","id":1,"label":"A"},
            {"type":"party.supplier","id":2,"label":"B"},
        ],1)}
        text,meta=BS.compare_suppliers(w,job,BS.context_entities(job))
        self.assertEqual(meta["mode"],"procurement_supplier_compare")
        self.assertFalse(any(name.startswith("create_") for name,_ in w.calls))
        self.assertIn("بهترین تأمین‌کننده",text)

    def test_specific_trade_case_review_uses_three_grounded_reads(self):
        w=Dummy();job={"id":8,"company_id":1,**env([{"type":"trade.case","id":9,"label":"TRD-1","code":"TRD-1"}],1)}
        text,meta=BS.trade_risk(w,job,BS.context_entities(job))
        self.assertEqual(meta["mode"],"trade_case_risk_review")
        self.assertEqual([x[0] for x in w.calls],["trade_case_snapshot","landed_cost_summary","trade_risk_summary"])
        self.assertIn("Projected Landed Cost",text)

    def test_conversation_explain_uses_bounded_history_metadata(self):
        job={"id":10,"company_id":1,"context":{"conversation_history":[{
            "prompt":"وضعیت شرکت چطور است؟",
            "result_text":"فروش ثبت‌شده 100 ریال",
            "mode":"trade_manager_brief_read",
            "tools_used":["trade_manager_brief"],
        }]}}
        text,meta=BS.explain_previous(job)
        self.assertEqual(meta["mode"],"grounded_conversation_explain_read")
        self.assertIn("بریف بین‌ماژولی مدیر",text)
        self.assertIn("پرسش قبلی",text)

    def test_worker_install_narrows_descriptors_for_read_intent(self):
        class W:
            def __init__(self): self.traces=[]
            def trace(self,*a,**k): self.traces.append((a,k))
            def model_for(self,role):return "qwen3.5:0.8b"
            def ollama_chat(self,*a,**k):return {"message":{"content":"{\"capabilities\":[]}"}}
        seen={}
        def original(self,j,t):
            seen["tools"]=[x["name"] for x in t];return "ok",{"mode":"tool_agent","tools_used":[]}
        W.process_agent=original
        BS.install_business_skills(W)
        w=W()
        tools=[{"name":"sales_margin_summary"},{"name":"search_sales_documents"},{"name":"create_voucher_draft"}]
        text,meta=w.process_agent({"id":1,"company_id":1,"prompt":"حاشیه سود این فروش را بررسی کن",**env([],1)},tools)
        self.assertNotIn("create_voucher_draft",seen["tools"])
        self.assertIn("sales_margin_summary",seen["tools"])
        self.assertEqual(meta["capability_retrieval"]["source"],"deterministic_lexical")

    def test_exact_multiword_margin_phrase_routes_without_model(self):
        class W:
            def __init__(self): self.traces=[];self.model_calls=0
            def trace(self,*a,**k): self.traces.append((a,k))
            def model_for(self,role): return "qwen3.5:0.8b"
            def ollama_chat(self,*a,**k): self.model_calls+=1;return {"message":{"content":"{\"capabilities\":[]}"}}
        w=W();selected,source=BS.retrieve(w,{"id":1,"company_id":1,**env([],1)},"حاشیه سود این فروش را بررسی کن")
        self.assertEqual(selected,["sales-margin"]);self.assertEqual(source,"deterministic_lexical");self.assertEqual(w.model_calls,0)

    def test_read_path_strips_proposal_descriptors_even_without_capability(self):
        tools=[
            {"name":"trial_balance","mode":"read"},
            {"name":"create_voucher_draft","mode":"proposal"},
            {"name":"reserve_sales_stock","mode":"proposal"},
        ]
        safe=BS._filter_tools(tools,[])
        self.assertEqual([x["name"] for x in safe],["trial_balance"])

    def test_read_path_name_guard_is_fail_closed_when_mode_missing(self):
        tools=[
            {"name":"sales_margin_summary"},
            {"name":"create_voucher_draft"},
            {"name":"deliver_sales_stock"},
        ]
        safe=BS._filter_tools(tools,["sales-margin"])
        self.assertEqual([x["name"] for x in safe],["sales_margin_summary"])

    def test_selected_capability_never_broadens_when_tools_are_unavailable(self):
        tools=[{"name":"trial_balance","mode":"read"},{"name":"create_voucher_draft","mode":"proposal"}]
        self.assertEqual(BS._filter_tools(tools,["sales-margin"]),[])

    def test_write_path_still_receives_full_proposal_descriptor_set(self):
        class W: pass
        seen={}
        def original(self,j,t): seen["names"]=[x["name"] for x in t];return "ok",{"mode":"write"}
        W.process_agent=original;BS.install_business_skills(W)
        w=W();w.trace=lambda *a,**k:None
        tools=[{"name":"trade_risk_summary","mode":"read"},{"name":"create_trade_case","mode":"proposal"}]
        w.process_agent({"id":1,"company_id":1,"prompt":"یک پرونده بازرگانی ایجاد کن"},tools)
        self.assertEqual(seen["names"],["trade_risk_summary","create_trade_case"])

class Cycle12SourceContracts(unittest.TestCase):
    def read(self,path):
        return (ROOT/path).read_text(encoding="utf-8")

    def test_server_capability_registry_matches_primary_skill_ids(self):
        s=self.read("app/Core/AiCapabilityRegistry.php")
        for cid in ["customer-review","compare-customers","supplier-review","compare-suppliers","trade-risk","inventory-risk","executive-brief","explain-previous"]:
            self.assertIn("'id'=>'"+cid+"'",s)
        self.assertIn("Tenant::can",s)

    def test_copilot_config_exposes_skill_catalog(self):
        boot=self.read("app/bootstrap.php")
        cop=self.read("app/Core/BusinessCopilot.php")
        self.assertIn("AiCapabilityRegistry.php",boot)
        self.assertIn("'skills'=>AiCapabilityRegistry::catalog()",cop)

    def test_cycle12_ui_has_slash_picker_and_viewport_clamp(self):
        js=self.read("assets/business-copilot-cycle12.js")
        css=self.read("assets/business-copilot-cycle12.css")
        idx=self.read("index.php")
        cop=self.read("app/Core/BusinessCopilot.php")
        self.assertIn("slashToken",js)
        self.assertIn("/ مهارت‌ها",js)
        self.assertIn("fitFloating",js)
        self.assertIn("ResizeObserver",js)
        self.assertIn("business-copilot-cycle12.css?v=10.9.0",idx)
        self.assertIn("business-copilot-cycle12.js?v=10.9.0",cop)
        self.assertEqual(idx.count("business-copilot-cycle12.css?v=10.9.0"),1)
        self.assertEqual(cop.count("business-copilot-cycle12.js?v=10.9.0"),1)
        self.assertIn("max-height",css)

    def test_conversation_history_is_same_user_company_and_bounded(self):
        repo=self.read("app/Core/AiRepository.php")
        self.assertIn("conversationHistoryForQueue",repo)
        self.assertIn("$context['conversation_history']=$history",repo)
        self.assertIn("requested_by=?",repo)
        self.assertIn("company_id=?",repo)
        self.assertIn("LIMIT 3",repo)
        self.assertIn("safeToolNames",repo)

    def test_live_stage_labels_cover_cycle12_capability_traces(self):
        live=self.read("assets/ai-live.js")
        self.assertIn('capability_retrieval: "',live)
        self.assertIn('capability_retrieval_fallback: "',live)

    def test_worker_installs_capability_layer_before_commercial_guard(self):
        w=self.read("engine/worker.py")
        self.assertIn("from business_skills import install_business_skills",w)
        self.assertLess(w.index("install_business_skills"),w.index("install_commercial_hardening"))
        self.assertIn("_install_business_skills(Worker)",w)

    def test_worker_image_copies_business_skills_module(self):
        dockerfile=self.read("engine/Dockerfile")
        self.assertIn("business_skills.py",dockerfile)
        self.assertIn("RUN python -m compileall -q /app",dockerfile)

if __name__=="__main__": unittest.main()
