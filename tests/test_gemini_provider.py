"""Offline Gemini routing tests: no API key or network required."""
import importlib.util
import json
import os
from pathlib import Path
from unittest import TestCase
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('gemini_provider_under_test',ROOT/'engine'/'provider_gateway.py')
pg=importlib.util.module_from_spec(spec)
spec.loader.exec_module(pg)


def config():
    return {
        'provider_strategy':'cloud_only','chat_model':'unused-local-model',
        'cloud_provider':{
            'enabled':True,'name':'gemini','base_url':'https://generativelanguage.googleapis.com/v1beta/openai',
            'api_key':'env:GEMINI_API_KEY','chat_model':'gemini-3.8-flash',
            'fast_model':'gemini-3.8-flash','agent_model':'gemini-3.8-flash',
            'analysis_model':'gemini-3.8-flash',
            'allowed_models':['gemini-3.8-flash','gemini-3.6-flash'],
        },
    }


class GeminiGatewayTests(TestCase):
    def test_env_key_resolution(self):
        with patch.dict(os.environ,{'GEMINI_API_KEY':'fake-non-secret'},clear=False):
            self.assertEqual(pg._cloud_cfg(config())['api_key'],'fake-non-secret')
            pg.validate_provider_config(config())

    def test_missing_env_fails_closed(self):
        with patch.dict(os.environ, {}, clear=True):
            with self.assertRaises(pg.ProviderGatewayError):
                pg.validate_provider_config(config())

    def test_model_allowlist_never_broadens(self):
        c=config()
        self.assertEqual(c['cloud_provider']['allowed_models'], ['gemini-3.8-flash', 'gemini-3.6-flash'])

    def test_strict_job_selection_and_no_ollama(self):
        class FakeWorker:
            def __init__(self,cfg):
                self.cfg=cfg
                self.models=[]
                self.base_payload={'models':[],'metadata':{}}
            def trace(self,*args,**kwargs):pass
            def model_for(self,role):return 'unused-local-model'
            def ollama_chat(self,*args,**kwargs):raise AssertionError('Ollama must not be invoked')
            def process_agent(self,job,tools):
                result=self.ollama_chat(job,0,[{'role':'user','content':'hello'}],[])
                return result['message']['content'],{}
        cloud_calls=[]
        def fake_cloud(worker,job,round_no,messages,tools,role,np,t,timeout,rf,selected_model=None):
            cloud_calls.append(selected_model)
            return {'message':{'role':'assistant','content':'ok'},'_metrics':{'model':selected_model},'_provider':'gemini'}
        with patch.dict(os.environ,{'GEMINI_API_KEY':'fake-non-secret'}),patch.object(pg,'_cloud_chat',fake_cloud):
            pg.install_provider_gateway(FakeWorker)
            w=FakeWorker(config())
            result=w.ollama_chat({'id':1,'context':{'engine_selection':{
                'provider':'gemini','model':'gemini-3.6-flash'}}},0,[{'role':'user','content':'Hello'}],[])
            self.assertEqual(result['message']['content'],'ok')
            self.assertEqual(cloud_calls,['gemini-3.6-flash'])
            with self.assertRaises(pg.ProviderGatewayError):
                w.ollama_chat({'id':2,'context':{'engine_selection':{
                    'provider':'gemini','model':'unexpected-model'}}},0,[],[])
            with self.assertRaises(pg.ProviderGatewayError):
                w.ollama_chat({'id':3,'context':{'engine_selection':{
                    'provider':'ollama','model':'gemma3:4b'}}},0,[],[])
            self.assertEqual(cloud_calls,['gemini-3.6-flash'])
