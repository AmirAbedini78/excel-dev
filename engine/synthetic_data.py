#!/usr/bin/env python3
"""Generate realistic-but-fake accounting datasets for load/AI experiments.

The generator intentionally includes seasonality, customer payment behavior,
late receivables, recurring expenses and a small amount of labelled anomalies.
It is for testing, benchmarking and model prototyping—not a substitute for real
production labels when validating financial predictions.
"""
from __future__ import annotations
import argparse, csv, math, random
from datetime import date, timedelta
from pathlib import Path

FIRST=['علی','رضا','مهدی','محمد','سارا','مریم','نگار','حسین','امیر','زهرا','آرمان','نیلوفر']
LAST=['احمدی','محمدی','رضایی','کریمی','حسینی','مرادی','کاظمی','اکبری','جعفری','صادقی','نوری','اسدی']
BUSINESS=['پارس','آریا','سپهر','نوین','دانا','پیشرو','نگین','آفتاب','بهین','پردازش','تجارت','صنعت']
ITEMS=['کالای عمومی','مواد اولیه','قطعه مصرفی','خدمات مشاوره','خدمات پشتیبانی','محصول A','محصول B','لوازم اداری','خدمات نصب','اشتراک نرم‌افزار']


def wcsv(path, headers, rows):
    with path.open('w',newline='',encoding='utf-8-sig') as f:
        w=csv.DictWriter(f,fieldnames=headers);w.writeheader();w.writerows(rows)


def month_factor(d: date) -> float:
    # deliberately synthetic pattern: year-end and autumn are busier.
    return {1:.9,2:.92,3:.98,4:1.0,5:1.04,6:1.06,7:1.08,8:1.12,9:1.17,10:1.22,11:1.30,12:1.42}[d.month]


def main():
    ap=argparse.ArgumentParser();ap.add_argument('--out',default='data/synthetic');ap.add_argument('--seed',type=int,default=1405);ap.add_argument('--companies',type=int,default=5);ap.add_argument('--parties',type=int,default=800);ap.add_argument('--items',type=int,default=250);ap.add_argument('--transactions',type=int,default=50000);ap.add_argument('--months',type=int,default=24);a=ap.parse_args();rnd=random.Random(a.seed)
    out=Path(a.out); out=out if out.is_absolute() else Path(__file__).resolve().parent/out;out.mkdir(parents=True,exist_ok=True)
    companies=[{'company_id':i,'name':f"{rnd.choice(BUSINESS)} {rnd.choice(BUSINESS)} {i}",'type':rnd.choice(['services','commerce','production'])} for i in range(1,a.companies+1)]
    parties=[]
    for i in range(1,a.parties+1):
        cid=rnd.randint(1,a.companies); kind=rnd.choices(['customer','supplier','both'],[.52,.36,.12])[0]; delay=max(0,int(rnd.gauss(18,15)))
        parties.append({'party_id':i,'company_id':cid,'name':f"{rnd.choice(FIRST)} {rnd.choice(LAST)} / {rnd.choice(BUSINESS)}",'type':kind,'payment_delay_days':delay,'credit_risk':round(min(.98,max(.01,rnd.betavariate(2,8))),4)})
    items=[]
    for i in range(1,a.items+1):
        cid=rnd.randint(1,a.companies); base=rnd.randint(2_000_000,200_000_000)
        items.append({'item_id':i,'company_id':cid,'name':f"{rnd.choice(ITEMS)} {i}",'type':rnd.choice(['goods','goods','service']),'base_cost':base,'base_price':int(base*rnd.uniform(1.12,1.8))})
    by_cust={c:[p for p in parties if p['company_id']==c and p['type'] in ('customer','both')] for c in range(1,a.companies+1)}
    by_sup={c:[p for p in parties if p['company_id']==c and p['type'] in ('supplier','both')] for c in range(1,a.companies+1)}
    by_item={c:[x for x in items if x['company_id']==c] for c in range(1,a.companies+1)}
    today=date.today(); start=today-timedelta(days=30*a.months); sales=[]; purchases=[]; payments=[]; anomalies=[]
    for tx in range(1,a.transactions+1):
        cid=rnd.randint(1,a.companies); its=by_item[cid]
        if not its: continue
        d=start+timedelta(days=rnd.randint(0,max(1,(today-start).days))); item=rnd.choice(its); qty=max(1,round(rnd.lognormvariate(.6,.75),2)); seasonal=month_factor(d)
        if rnd.random()<.60 and by_cust[cid]:
            p=rnd.choice(by_cust[cid]); unit=int(item['base_price']*seasonal*rnd.uniform(.92,1.08)); amount=int(qty*unit); anomaly=1 if rnd.random()<.006 else 0
            if anomaly: amount*=rnd.choice([5,8,12]); anomalies.append({'entity':'sale','transaction_id':tx,'reason':'amount_outlier'})
            due=d+timedelta(days=rnd.choice([0,7,15,30,45])); sales.append({'sale_id':tx,'company_id':cid,'party_id':p['party_id'],'item_id':item['item_id'],'date':d.isoformat(),'due_date':due.isoformat(),'quantity':qty,'unit_price':unit,'net_total':amount,'is_anomaly':anomaly})
            paid_delay=max(0,int(rnd.gauss(int(p['payment_delay_days']),10))); paid=due+timedelta(days=paid_delay); late=int(paid>due)
            if paid<=today and rnd.random()>.05: payments.append({'payment_id':len(payments)+1,'company_id':cid,'party_id':p['party_id'],'source':'sale','source_id':tx,'date':paid.isoformat(),'amount':amount,'days_late':max(0,(paid-due).days),'late_label':late})
        elif by_sup[cid]:
            p=rnd.choice(by_sup[cid]); unit=int(item['base_cost']*rnd.uniform(.9,1.15)); amount=int(qty*unit); purchases.append({'purchase_id':tx,'company_id':cid,'party_id':p['party_id'],'item_id':item['item_id'],'date':d.isoformat(),'quantity':qty,'unit_cost':unit,'net_total':amount})
    wcsv(out/'companies.csv',companies[0].keys(),companies);wcsv(out/'parties.csv',parties[0].keys(),parties);wcsv(out/'items.csv',items[0].keys(),items)
    if sales:wcsv(out/'sales.csv',sales[0].keys(),sales)
    if purchases:wcsv(out/'purchases.csv',purchases[0].keys(),purchases)
    if payments:wcsv(out/'payments.csv',payments[0].keys(),payments)
    if anomalies:wcsv(out/'anomalies.csv',anomalies[0].keys(),anomalies)
    print(f"generated companies={len(companies)} parties={len(parties)} items={len(items)} sales={len(sales)} purchases={len(purchases)} payments={len(payments)} anomalies={len(anomalies)} in {out}")
if __name__=='__main__':main()
