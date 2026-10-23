// The CSP build is pure JavaScript: it needs neither eval nor WebAssembly permissions.
import { heicTo } from 'heic-to/csp';

const scope = self as unknown as {
  onmessage: ((event: MessageEvent<{ file: Blob }>) => void) | null;
  postMessage: (message: { bitmap: ImageBitmap } | { error: string }, transfer?: Transferable[]) => void;
};

scope.onmessage = (event) => {
  void heicTo({ blob: event.data.file, type: 'bitmap' }).then((bitmap) => {
    scope.postMessage({ bitmap }, [bitmap]);
  }, () => scope.postMessage({ error: 'HEIC/HEIF decoding failed.' }));
};
