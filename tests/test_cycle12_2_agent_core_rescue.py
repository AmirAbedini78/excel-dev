from __future__ import annotations

import json
import sys
import unittest
from unittest.mock import patch
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
ENGINE=Path('/app') if Path('/app/business_supervisor.py').exists() else ROOT/'engine'
sys.path.insert(0,str(ENGINE))

import business_supervisor as SUP


def env(entities,company_id=1):
    return {"context":{"context_envelope":{"version":"v2","validated":True,"company_id":company_id,"attached_entities":entities,"current_page":{"entities":[]}}}}


class Dummy:
    def __init__(self, invalid_first=False):
        self.calls=[];self.traces=[];self.invalid_first=invalid_first;self.model_calls=[]
    def trace(self,job,stage,message,details=None):
        self.traces.append((stage,message,details or {}))
    def model_for(self,role):
        return "analysis-test" if role=="analysis" else "fallback-test"
    def tool(self,job,name,args,call_id):
        self.calls.append((name,args))
        if name=="supplier_performance_summary":
            return {
                "period":{"months":6,"status_scope":"confirmed"},
                "rows":[
                    {"party_id":1,"code":"SUP-A","name":"سپهر","purchase_document_count":4,"purchase_net_total":1200000000,
                     "ordered_qty":100,"accepted_qty":95,"rejected_qty":2,"open_qty":5,"receipt_fill_rate":.95,"receipt_acceptance_rate":95/97,
                     "trade_case_count":3,"active_trade_case_count":1,"high_risk_cases":0,"medium_risk_cases":1,"max_delay_days":2,
                     "cost_overrun_cases":0,"arrival_sample":3,"on_time_arrivals":3,"late_arrivals":0,"on_time_rate":1.0},
                    {"party_id":2,"code":"SUP-B","name":"کارن","purchase_document_count":5,"purchase_net_total":1800000000,
                     "ordered_qty":140,"accepted_qty":100,"rejected_qty":10,"open_qty":40,"receipt_fill_rate":100/140,"receipt_acceptance_rate":100/110,
                     "trade_case_count":4,"active_trade_case_count":2,"high_risk_cases":1,"medium_risk_cases":1,"max_delay_days":9,
                     "cost_overrun_cases":1,"arrival_sample":2,"on_time_arrivals":1,"late_arrivals":1,"on_time_rate":.5},
                ],
                "limitations":{"quality":"کیفیت/SLA کامل نیست"}
            }
        if name=="shipment_commitment_impact":
            return {
                "trade_case_id":9,
                "case":{"case_no":"TRD-9","clearance_status":"hold"},
                "shipment":{"status":"planned","eta":"1405/06/25"},
                "trade_risk":{"risk_level":"high","delay_days":4,"shipment_status":"planned","eta":"1405/06/25"},
                "item_exposure":[
                    {"item_id":7,"item_code":"PLC-S7-1200","item_name":"PLC S7-1200","available":2,"reserved":3,"expected_inbound":10,
                     "outstanding_sales_qty":8,"reserved_for_affected_sales":3,"unreserved_outstanding_qty":5,
                     "trade_case_open_inbound":10,"uncovered_after_current_available":3,"depends_on_future_inbound":True,"potential_dependency_on_this_trade_case":True}
                ],
                "affected_sales":[
                    {"sales_doc_id":20,"document_no":"SAL-20","document_date":"1405/06/20","workflow_status":"approved",
                     "customer_id":4,"customer_name":"کارخانه بهین","outstanding_quantity":5,"reserved_quantity":2,"unreserved_quantity":3,"items":[]}
                ],
                "summary":{"affected_sales_count":1,"affected_customer_count":1,"items_with_future_inbound_dependency":1,"items_with_trade_case_dependency_signal":1},
                "limitations":{"causality":"اثر، Exposure است نه علیت قطعی"}
            }
        if name=="trade_risk_summary":
            return {"rows":[
                {"trade_case_id":9,"case_no":"TRD-9","supplier_name":"تامین برق ایرانیان","status":"customs",
                 "shipment_no":"SHP-9","shipment_status":"planned","eta":"1405/06/25","delay_days":0,
                 "clearance_status":"hold","projected_landed_total_irr":620000000,"risk_score":4,"risk_level":"high",
                 "reasons":["customs_hold"]}
            ],"risk_count":1}
        raise AssertionError(name)
    def ollama_chat(self,job,round_no,messages,tools,**kwargs):
        self.model_calls.append(kwargs.get("model"))
        if self.invalid_first and len(self.model_calls)==1:
            return {"message":{"content":json.dumps({
                "summary":{"text":"ریسک 999 درصد است","evidence_ids":["E1"]},
                "findings":[],"actions":[],"limitations":[]
            },ensure_ascii=False)}}
        content=json.loads(messages[-1]["content"])
        kind=content["kind"]
        if kind=="supplier_performance":
            data={
                "summary":{"text":"حجم خرید بیشتر به‌تنهایی معیار برتری نیست و شواهد قابلیت اتکای سپهر بهتر است.","evidence_ids":["E1","E6","E8","E10","E15","E17"]},
                "findings":[{"text":"کارن هم‌زمان ریسک پرونده و تأخیر بیشتری دارد.","evidence_ids":["E15","E16"]}],
                "actions":[{"text":"قبل از سفارش بعدی، علت خرید باز و ریسک پرونده کارن بررسی شود.","evidence_ids":["E14","E15"]}],
                "limitations":["کیفیت و SLA کامل نیست."]
            }
        elif kind=="shipment_commitment_impact":
            data={
                "summary":{"text":"این محموله با یک تعهد فروش باز هم‌کالا مرتبط است و پوشش فعلی برای آن کامل نیست.","evidence_ids":["E6","E8","E14"]},
                "findings":[{"text":"بخشی از تعهد باز به ورودی آینده وابسته است.","evidence_ids":["E14"]}],
                "actions":[{"text":"تعهد مشتری و وضعیت ETA پیش از وعده تحویل بعدی بازبینی شود.","evidence_ids":["E5","E16"]}],
                "limitations":["این نتیجه Exposure را نشان می‌دهد، نه علیت قطعی."]
            }
        else:
            data={
                "summary":{"text":"ریسک اصلی از توقف گمرکی می‌آید، نه از تأخیر ثبت‌شده حمل.","evidence_ids":["E2","E5","E6"]},
                "findings":[{"text":"پرونده در وضعیت پرریسک قرار دارد و علت قطعی آن توقف گمرکی است.","evidence_ids":["E1","E2","E6"]}],
                "actions":[{"text":"رفع وضعیت توقف گمرکی باید پیش از تغییر وعده‌های عملیاتی پیگیری شود.","evidence_ids":["E2","E6"]}],
                "limitations":[]
            }
        return {"message":{"content":json.dumps(data,ensure_ascii=False)}}


class Cycle122AgentCoreRescue(unittest.TestCase):
    def test_supplier_intent_benchmark(self):
        prompts=[
            "کدوم تامین‌کننده عملکرد بهتری داشته؟",
            "بهترین تأمین‌کننده برای ادامه همکاری کدومه؟",
            "تامین‌کننده‌ها رو از نظر عملکرد و ریسک مقایسه کن",
            "بین این دو تأمین‌کننده کدام قابل اتکاتر است؟",
        ]
        for p in prompts:self.assertTrue(SUP._supplier_intent(p,[]),p)

    def test_shipment_impact_intent_benchmark(self):
        entities=[{"type":"trade.case","id":9}]
        prompts=[
            "اگر این محموله دیر برسد کدام تعهدهای فروش تحت تأثیر قرار می‌گیرند؟",
            "اثر تأخیر این پرونده روی مشتری‌ها چیه؟",
            "/shipment-impact این پرونده چه اثری روی فروش دارد؟",
        ]
        for p in prompts:self.assertTrue(SUP._shipment_impact_intent(p,entities),p)

    def test_trade_risk_intent_benchmark(self):
        prompts=[
            "چه چیزی الان در بازرگانی بیشترین ریسک را دارد و چرا؟",
            "/trade-risk ریسک‌های فوری شرکت چیه؟",
            "پرریسک‌ترین پرونده بازرگانی کدام است؟",
        ]
        for p in prompts:self.assertTrue(SUP._trade_risk_intent(p,[]),p)
        self.assertFalse(SUP._trade_risk_intent('/shipment-impact اثر این محموله روی فروش چیست؟',[]))

    def test_supplier_flow_uses_one_server_evidence_tool_and_reasoning(self):
        class W(Dummy):pass
        W.process_agent=lambda self,j,t:("old",{"mode":"old"})
        SUP.install_business_supervisor(W)
        w=W();job={"id":1,"company_id":1,"prompt":"کدوم تامین‌کننده عملکرد بهتری داشته؟",**env([])}
        tools=[{"name":"supplier_performance_summary","mode":"read"}]
        text,meta=w.process_agent(job,tools)
        self.assertEqual([x[0] for x in w.calls],["supplier_performance_summary"])
        self.assertEqual(meta["mode"],"supplier_performance_supervisor_read")
        self.assertEqual(meta["synthesis"],"analysis_model")
        self.assertEqual(meta["supervisor"],"bounded_plan_v1")
        self.assertIn("Structured Evidence",text);self.assertIn("تحلیل هوشمند",text)
        self.assertTrue(any(x[0]=="supervisor_plan" for x in w.traces))

    def test_supplier_two_mentions_are_server_ids_not_model_ids(self):
        class W(Dummy):pass
        W.process_agent=lambda self,j,t:("old",{"mode":"old"})
        SUP.install_business_supervisor(W)
        w=W();job={"id":2,"company_id":1,"prompt":"این دو تامین‌کننده را مقایسه کن",**env([
            {"type":"party.supplier","id":11,"label":"A"},{"type":"party.supplier","id":12,"label":"B"}
        ])}
        w.process_agent(job,[{"name":"supplier_performance_summary","mode":"read"}])
        self.assertEqual(w.calls[0][1]["party_ids"],[11,12])

    def test_shipment_impact_uses_cross_module_evidence_tool(self):
        class W(Dummy):pass
        W.process_agent=lambda self,j,t:("old",{"mode":"old"})
        SUP.install_business_supervisor(W)
        w=W();job={"id":3,"company_id":1,"prompt":"اگر این محموله دیر برسد چه تعهدهای فروش تحت تأثیر قرار می‌گیرند؟",**env([
            {"type":"trade.case","id":9,"label":"TRD-9"}
        ])}
        text,meta=w.process_agent(job,[{"name":"shipment_commitment_impact","mode":"read"}])
        self.assertEqual(w.calls[0][0],"shipment_commitment_impact")
        self.assertEqual(w.calls[0][1]["trade_case_id"],9)
        self.assertEqual(meta["mode"],"shipment_commitment_impact_read")
        self.assertIn("تعهد فروش",text);self.assertIn("Evidence",text)

    def test_shipment_impact_without_entity_fails_closed(self):
        class W(Dummy):pass
        W.process_agent=lambda self,j,t:("old",{"mode":"old"})
        SUP.install_business_supervisor(W)
        w=W();job={"id":4,"company_id":1,"prompt":"اگر محموله دیر برسد کدام تعهد فروش آسیب می‌بیند؟",**env([])}
        text,meta=w.process_agent(job,[{"name":"shipment_commitment_impact","mode":"read"}])
        self.assertEqual(meta["mode"],"shipment_commitment_impact_blocked")
        self.assertEqual(w.calls,[]);self.assertIn("@",text)

    def test_trade_risk_uses_structured_supervisor_and_reasoning(self):
        class W(Dummy):pass
        W.process_agent=lambda self,j,t:("old",{"mode":"old"})
        SUP.install_business_supervisor(W)
        w=W();job={"id":31,"company_id":1,"prompt":"چه چیزی الان در بازرگانی بیشترین ریسک را دارد و چرا؟",**env([])}
        text,meta=w.process_agent(job,[{"name":"trade_risk_summary","mode":"read"}])
        self.assertEqual([x[0] for x in w.calls],["trade_risk_summary"])
        self.assertEqual(meta["mode"],"trade_risk_supervisor_read")
        self.assertEqual(meta["synthesis"],"analysis_model")
        self.assertIn("توقف گمرکی",text);self.assertIn("تحلیل هوشمند",text)

    def test_supplier_source_uses_canonical_confirmed_docs_not_hardcoded_doc_types(self):
        domain=(ROOT/'app/Core/BusinessIntelligenceDomain.php').read_text(encoding='utf-8')
        start=domain.index('public static function supplierPerformanceSummary')
        end=domain.index('public static function shipmentCommitmentImpact')
        block=domain[start:end]
        base=block[:block.index('$lineSql=')]
        self.assertIn("d.workflow_status IN ('approved','final')",base)
        self.assertNotIn("purchase_order_goods",base)
        self.assertNotIn("purchase_invoice_goods",base)
        self.assertIn("COALESCE(i.item_type,'material')<>'service'",block)

    def test_trade_overview_resolves_only_single_active_case_as_page_context(self):
        php=(ROOT/'app/Core/BusinessCopilot.php').read_text(encoding='utf-8')
        self.assertIn("$_GET['case_id']??$_GET['view']??0",php)
        self.assertIn("count($active)===1",php)
        self.assertIn("['closed','canceled']",php)

    def test_sidecar_flex_shell_pins_composer_and_thread_owns_scroll(self):
        css=(ROOT/'assets/business-copilot-cycle12.css').read_text(encoding='utf-8')
        self.assertIn('display:flex!important;flex-direction:column',css)
        self.assertIn('flex:1 1 0!important;height:0!important',css)
        self.assertIn('.copilot-sidecar>.copilot-composer{max-height:min(40dvh,320px)',css)
        self.assertIn('overflow:hidden!important',css)

    def test_write_request_bypasses_supervisor(self):
        class W(Dummy):pass
        W.process_agent=lambda self,j,t:("guarded",{"mode":"guarded"})
        SUP.install_business_supervisor(W)
        w=W();text,meta=w.process_agent({"id":5,"company_id":1,"prompt":"یک پرونده بازرگانی ایجاد کن",**env([])},[{"name":"shipment_commitment_impact","mode":"read"}])
        self.assertEqual(text,"guarded");self.assertEqual(w.calls,[])

    def test_invalid_numeric_claim_falls_to_second_model(self):
        w=Dummy(invalid_first=True);job={"id":6,"company_id":1,"prompt":"کدوم تامین‌کننده بهتره؟",**env([])}
        data=w.tool(job,"supplier_performance_summary",{"months":6,"limit":5},"x")
        pack=SUP._supplier_pack(data,job["prompt"])
        text,meta=SUP._synthesize(w,job,job["prompt"],pack,{"mode":"x","tools_used":["supplier_performance_summary"]})
        self.assertEqual(w.model_calls,["analysis-test","fallback-test"])
        self.assertEqual(meta["synthesis"],"analysis_model")
        self.assertNotIn("999",text)

    def test_stale_runtime_route_never_displaces_current_fallback(self):
        w=Dummy()
        with patch.object(SUP.Path,"read_text",return_value=json.dumps({"selected_model":"stale-test"})):
            self.assertEqual(SUP._runtime_analysis_models(w),["analysis-test","fallback-test"])
        with patch.object(SUP.Path,"read_text",return_value=json.dumps({"selected_model":"fallback-test"})):
            self.assertEqual(SUP._runtime_analysis_models(w),["fallback-test","analysis-test"])

    def test_php_registers_two_typed_intelligence_tools(self):
        registry=(ROOT/'app/Core/AiToolRegistry.php').read_text(encoding='utf-8')
        domain=(ROOT/'app/Core/BusinessIntelligenceDomain.php').read_text(encoding='utf-8')
        bootstrap=(ROOT/'app/bootstrap.php').read_text(encoding='utf-8')
        for name in ['supplier_performance_summary','shipment_commitment_impact']:
            self.assertIn("'name'=>'"+name+"'",registry);self.assertIn("'"+name+"'=>BusinessIntelligenceDomain::",registry)
        self.assertIn('supplierPerformanceSummary',domain);self.assertIn('shipmentCommitmentImpact',domain)
        self.assertIn("BusinessIntelligenceDomain.php",bootstrap)

    def test_tool_is_read_only_and_proposal_paths_untouched(self):
        registry=(ROOT/'app/Core/AiToolRegistry.php').read_text(encoding='utf-8')
        self.assertIn("['name'=>'supplier_performance_summary','mode'=>'read'",registry)
        self.assertIn("['name'=>'shipment_commitment_impact','mode'=>'read'",registry)
        self.assertIn("['name'=>'create_trade_case','mode'=>'proposal'",registry)

    def test_ui_menus_use_sidecar_overlay_not_scrollable_composer(self):
        js=(ROOT/'assets/business-copilot-cycle12.js').read_text(encoding='utf-8')
        css=(ROOT/'assets/business-copilot-cycle12.css').read_text(encoding='utf-8')
        self.assertIn("shell.appendChild(overlay)",js)
        self.assertIn("overlay.appendChild(mention)",js)
        self.assertIn("overlay.appendChild(menu)",js)
        self.assertIn('.copilot-overlay-layer',css)
        self.assertIn('pointer-events:none',css)

    def test_docker_image_contains_supervisor(self):
        docker=(ROOT/'engine/Dockerfile').read_text(encoding='utf-8')
        worker=(ROOT/'engine/worker.py').read_text(encoding='utf-8')
        self.assertIn('business_supervisor.py',docker)
        self.assertIn('cycle12_mvp_b_smoke.py',docker)
        self.assertIn('install_business_supervisor',worker)


    def test_semantic_benchmark_routes_38_prompts(self):
        rows=json.loads((ROOT/'tests/fixtures/cycle12_mvp_b_semantic_benchmark.json').read_text(encoding='utf-8'))
        self.assertGreaterEqual(len(rows),36)
        for row in rows:
            entities=[{"type":t,"id":i} for t,i in row.get("entities",[])]
            prompt=row["prompt"]
            if SUP.BS.is_write_request(prompt):
                actual="write-bypass"
            elif SUP._shipment_impact_intent(prompt,entities):
                actual="shipment-impact"
            elif SUP._trade_risk_intent(prompt,entities):
                actual="trade-risk"
            elif SUP._supplier_intent(prompt,entities):
                actual="supplier-review"
            else:
                selected=SUP.BS.lexical_retrieve(prompt,entities)
                actual=selected[0] if selected else "model-fallback"
            self.assertEqual(actual,row["expected"],prompt)

    def test_explain_previous_labels_new_evidence_tools(self):
        job={"id":77,"company_id":1,"context":{"conversation_history":[{
            "prompt":"کدوم تامین‌کننده بهتره؟","result_text":"پاسخ","mode":"supplier_performance_supervisor_read",
            "tools_used":["supplier_performance_summary","shipment_commitment_impact"]
        }]}}
        text,_=SUP.BS.explain_previous(job)
        self.assertIn("Evidence Pack عملکرد تأمین‌کننده",text)
        self.assertIn("اثر محموله بر تعهدهای فروش",text)

    def test_worker_capability_registry_exposes_shipment_impact(self):
        self.assertIn("shipment-impact",SUP.BS.CAPABILITIES)
        self.assertEqual(SUP.BS.explicit_skill("/shipment-impact @TRD"),"shipment-impact")
        self.assertEqual(SUP.BS.CAPABILITIES["shipment-impact"]["tools"],{"shipment_commitment_impact"})

    def test_capability_catalog_exposes_shipment_impact(self):
        catalog=(ROOT/'app/Core/AiCapabilityRegistry.php').read_text(encoding='utf-8')
        self.assertIn("'id'=>'shipment-impact'",catalog)
        self.assertIn('اثر محموله بر تعهدهای فروش',catalog)

    def test_selected_shipment_id_is_preserved_in_cross_module_evidence(self):
        domain=(ROOT/'app/Core/BusinessIntelligenceDomain.php').read_text(encoding='utf-8')
        self.assertIn('$requestedShipmentId=$shipmentId',domain)
        self.assertIn("(int)($candidate['id']??0)===$requestedShipmentId",domain)
        self.assertIn("'shipment'=>$selectedShipment",domain)
        self.assertIn("$selectedShipment['delay_days']=$delay",domain)

    def test_runtime_preflight_requires_structured_analysis_output(self):
        smoke=(ROOT/'engine/cycle12_mvp_b_smoke.py').read_text(encoding='utf-8')
        self.assertIn('ANALYSIS_MODEL_PREFLIGHT PASS',smoke)
        self.assertIn('response_format=_schema()',smoke)
        self.assertIn('timeout_seconds=timeout_seconds',smoke)
        self.assertIn('for role,timeout in (("analysis",150),("fallback",120))',smoke)

    def test_runtime_preflight_persists_verified_model_route(self):
        smoke=(ROOT/'engine/cycle12_mvp_b_smoke.py').read_text(encoding='utf-8')
        supervisor=(ROOT/'engine/business_supervisor.py').read_text(encoding='utf-8')
        self.assertIn('cycle12_analysis_route.json',smoke)
        self.assertIn('"selected_model":model',smoke)
        self.assertIn('cycle12_analysis_route.json',supervisor)
        self.assertIn('def _runtime_analysis_models',supervisor)


if __name__=='__main__':unittest.main()
