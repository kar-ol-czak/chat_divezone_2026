# emulator tunelu: nasluch 127.0.0.1:PORT, odpowiedz na SSLRequest wg trybu z pliku (N|EOF|CISZA)
import socket, sys, time, threading
port=int(sys.argv[1]); modef=sys.argv[2]
s=socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR,1); s.bind(('127.0.0.1',port)); s.listen(5)
def h(c):
    try:
        c.recv(8); m=open(modef).read().strip()
        if m=='N': c.sendall(b'N')
        elif m=='CISZA': time.sleep(8)
    finally: c.close()
while True:
    c,_=s.accept(); threading.Thread(target=h,args=(c,),daemon=True).start()
