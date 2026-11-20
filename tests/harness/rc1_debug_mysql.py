import pymysql

c = pymysql.connect(
    host="127.0.0.1",
    port=3307,
    user="p2p",
    password="p2p_test",
    database="erpsmart_p2p_test",
    charset="utf8mb4",
)
cur = c.cursor()
cur.execute("SHOW GRANTS")
print("GRANTS", cur.fetchall())
try:
    cur.execute("SET FOREIGN_KEY_CHECKS=0")
    cur.execute("DROP TABLE IF EXISTS companies")
    c.commit()
    print("drop ok")
except Exception as e:
    print("drop fail", e)
cur.execute("SHOW TABLES")
print("TABLES", [r[0] for r in cur.fetchall()])
c.close()
